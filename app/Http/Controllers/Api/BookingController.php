<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CoachAvailability;
use App\Models\NewSession;
use App\Models\Service;
use App\Models\MentorshipRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class BookingController extends Controller
{
    public function getAvailableDates(Request $request, $coachId)
    {
        // Validate query parameters
        $request->validate([
            'service_id' => 'required|exists:services,service_id',
            'month' => 'required|date_format:Y-m',
        ]);

        $serviceId = $request->query('service_id');
        $month = $request->query('month');

        // Verify that the coach provides the requested service
        $service = Service::where('service_id', $serviceId)
            ->where('coach_id', $coachId)
            ->first();

        if (!$service) {
            return response()->json(['message' => 'Service does not belong to this coach'], 403);
        }

        // Parse the month and determine the date range
        $startOfMonth = Carbon::parse($month)->startOfMonth();
        $endOfMonth = Carbon::parse($month)->endOfMonth();
        $currentDate = Carbon::today();

        // Fetch coach's availability
        $availabilities = CoachAvailability::where('coach_id', $coachId)->get();

        // Fetch booked sessions for the month
        $bookedSessions = NewSession::where('coach_id', $coachId)
            ->whereIn('status', ['pending', 'upcoming'])
            ->whereBetween('session_time', [$startOfMonth, $endOfMonth])
            ->get()
            ->groupBy(function ($session) {
                return Carbon::parse($session->session_time)->toDateString();
            });

        // Generate list of dates for the month
        $dates = [];
        $durationMinutes = 30; // Assuming 30-minute sessions as per screenshot

        for ($date = $startOfMonth->copy(); $date->lte($endOfMonth); $date->addDay()) {
            $dayOfWeek = $date->format('l');
            $dateString = $date->toDateString();

            // Check if the date is in the past
            if ($date->lt($currentDate)) {
                $dates[] = [
                    'date' => $dateString,
                    'day_of_week' => $dayOfWeek,
                    'status' => 'unavailable',
                ];
                continue;
            }

            // Check if the coach is available on this day
            $availability = $availabilities->firstWhere('Day_Of_Week', $dayOfWeek);

            if (!$availability) {
                $dates[] = [
                    'date' => $dateString,
                    'day_of_week' => $dayOfWeek,
                    'status' => 'unavailable',
                ];
                continue;
            }

            // Check if the date is fully booked
            $isBooked = false;
            if (isset($bookedSessions[$dateString])) {
                $sessionsOnDate = $bookedSessions[$dateString];
                $startTime = Carbon::parse($availability->Start_Time);
                $endTime = Carbon::parse($availability->End_Time);
                $totalMinutesAvailable = $endTime->diffInMinutes($startTime);
                $totalSlots = $totalMinutesAvailable / $durationMinutes;

                $bookedMinutes = 0;
                foreach ($sessionsOnDate as $session) {
                    $bookedMinutes += $session->duration_minutes;
                }

                $bookedSlots = $bookedMinutes / $durationMinutes;
                if ($bookedSlots >= $totalSlots) {
                    $isBooked = true;
                }
            }

            $status = $isBooked ? 'booked' : 'available';
            $dates[] = [
                'date' => $dateString,
                'day_of_week' => $dayOfWeek,
                'status' => $status,
            ];
        }

        return response()->json($dates);
    }

    public function getAvailableSlots(Request $request, $coachId)
    {
        // Validate query parameters
        $request->validate([
            'date' => 'required|date',
            'service_id' => 'required|exists:services,service_id',
        ]);

        $date = $request->query('date');
        $serviceId = $request->query('service_id');
        $dayOfWeek = Carbon::parse($date)->format('l');

        // Verify that the coach provides the requested service
        $service = Service::where('service_id', $serviceId)
            ->where('coach_id', $coachId)
            ->first();

        if (!$service) {
            return response()->json(['message' => 'Service does not belong to this coach'], 403);
        }

        // Check if the date is in the past
        $selectedDate = Carbon::parse($date);
        if ($selectedDate->lt(Carbon::today())) {
            return response()->json(['message' => 'Cannot book slots for past dates'], 400);
        }

        // Fetch coach's availability for the day
        $availabilities = CoachAvailability::where('coach_id', $coachId)
            ->where('Day_Of_Week', $dayOfWeek)
            ->get();

        if ($availabilities->isEmpty()) {
            return response()->json(['message' => 'No availability on this day'], 400);
        }

        // Fetch booked sessions for the date
        $bookedSessions = NewSession::where('coach_id', $coachId)
            ->whereIn('status', ['pending', 'upcoming'])
            ->whereDate('session_time', $selectedDate->toDateString())
            ->get();

        // Generate time slots (30-minute intervals)
        $slots = [];
        $durationMinutes = 30; // Assuming 30-minute sessions as per screenshot

        foreach ($availabilities as $availability) {
            $startTime = Carbon::parse($availability->Start_Time);
            $endTime = Carbon::parse($availability->End_Time);

            while ($startTime->lt($endTime)) {
                $slotEnd = $startTime->copy()->addMinutes($durationMinutes);
                if ($slotEnd->gt($endTime)) {
                    break; // Don't include partial slots
                }

                $slotStartFormatted = $startTime->format('H:i:s');
                $slotEndFormatted = $slotEnd->format('H:i:s');

                // Check if this slot is booked
                $isBooked = $bookedSessions->filter(function ($session) use ($startTime, $slotEnd) {
                    $sessionStart = Carbon::parse($session->session_time);
                    $sessionEnd = $sessionStart->copy()->addMinutes($session->duration_minutes);
                    return $startTime->lt($sessionEnd) && $slotEnd->gt($sessionStart);
                })->isNotEmpty();

                $slots[] = [
                    'start_time' => $slotStartFormatted,
                    'end_time' => $slotEndFormatted,
                    'status' => $isBooked ? 'unavailable' : 'available',
                ];

                $startTime->addMinutes($durationMinutes);
            }
        }

        return response()->json($slots);
    }

    public function bookService(Request $request, $coachId)
    {
        $request->validate([
            'service_id' => 'required|exists:services,service_id',
            'session_time' => 'required|date|after:now',
            'duration_minutes' => 'required|integer|min:30',
            'mentorship_request_id' => 'nullable|exists:mentorship_requests,id', // Optional for MentorshipPlan sessions
        ]);

        $service = Service::findOrFail($request->service_id);
        if ($service->coach_id !== (int)$coachId) {
            return response()->json(['message' => 'Service does not belong to this coach'], 403);
        }

        $slotStart = Carbon::parse($request->session_time);
        $slotEnd = $slotStart->copy()->addMinutes($request->duration_minutes);
        $dayOfWeek = $slotStart->format('l');

        // Check Coach Availability
        $availability = CoachAvailability::where('coach_id', (int)$coachId)
            ->where('Day_Of_Week', $dayOfWeek)
            ->where('Start_Time', '<=', $slotStart->format('H:i:s'))
            ->where('End_Time', '>=', $slotEnd->format('H:i:s'))
            ->first();

        if (!$availability) {
            Log::warning('Selected slot is not available', [
                'trainee_id' => Auth::id(),
                'coach_id' => $coachId,
                'date' => $slotStart->toDateString(),
                'day_of_week' => $dayOfWeek,
                'start_time' => $slotStart->format('H:i'),
                'duration' => $request->duration_minutes,
            ]);
            return response()->json(['message' => 'Selected slot is not available'], 400);
        }

        // Check for conflicts
        $conflictingSessions = NewSession::where('coach_id', (int)$coachId)
            ->whereIn('status', ['pending', 'upcoming'])
            ->whereDate('session_time', $slotStart->toDateString())
            ->get()
            ->filter(function ($existingSession) use ($slotStart, $slotEnd) {
                $reqStart = Carbon::parse($existingSession->session_time);
                $reqEnd = $reqStart->copy()->addMinutes($existingSession->duration_minutes);
                return $slotStart < $reqEnd && $slotEnd > $reqStart;
            });

        if ($conflictingSessions->isNotEmpty()) {
            Log::warning('Slot conflicts with existing sessions', [
                'trainee_id' => Auth::id(),
                'coach_id' => $coachId,
                'date' => $slotStart->toDateString(),
                'day_of_week' => $dayOfWeek,
                'start_time' => $slotStart->format('H:i'),
            ]);
            return response()->json(['message' => 'Selected slot is already reserved'], 400);
        }

        $mentorshipRequestId = $request->mentorship_request_id;
        $mentorshipRequest = null;

        // If mentorship_request_id is provided, validate it
        if ($mentorshipRequestId) {
            $mentorshipRequest = MentorshipRequest::findOrFail($mentorshipRequestId);

            if ($mentorshipRequest->trainee_id !== Auth::user()->User_ID) {
                return response()->json(['message' => 'This mentorship request does not belong to you.'], 403);
            }

            if ($mentorshipRequest->status !== 'accepted') {
                return response()->json(['message' => 'Mentorship request must be accepted to book sessions.'], 400);
            }

            if ($mentorshipRequest->requestable_type !== \App\Models\MentorshipPlan::class) {
                return response()->json(['message' => 'Mentorship request must be for a Mentorship Plan to book sessions this way.'], 400);
            }

            // Check if the service_id matches the mentorship request's service
            if ($mentorshipRequest->requestable->service_id !== $service->service_id) {
                return response()->json(['message' => 'Service does not match the mentorship request.'], 400);
            }

            // Check if the number of booked sessions doesn't exceed session_count
            $sessionCount = $mentorshipRequest->requestable->session_count;
            $bookedSessions = NewSession::where('mentorship_request_id', $mentorshipRequestId)->count();
            if ($bookedSessions >= $sessionCount) {
                return response()->json(['message' => 'You have already booked the maximum number of sessions for this Mentorship Plan.'], 400);
            }
        }

        DB::beginTransaction();
        try {
            $session = NewSession::create([
                'trainee_id' => Auth::user()->User_ID,
                'coach_id' => $coachId,
                'session_time' => $slotStart->toDateTimeString(),
                'duration_minutes' => $request->duration_minutes,
                'status' => 'pending', // Pending until payment is completed
                'service_id' => $service->service_id,
                'mentorship_request_id' => $mentorshipRequestId, // Will be null for regular services
            ]);

            // If this session is part of a MentorshipPlan, create a pending payment if all sessions are booked
            if ($mentorshipRequest) {
                $bookedSessions = NewSession::where('mentorship_request_id', $mentorshipRequestId)->count();
                if ($bookedSessions == $mentorshipRequest->requestable->session_count) {
                    \App\Models\PendingPayment::create([
                        'mentorship_request_id' => $mentorshipRequestId,
                        'payment_due_at' => now()->addHours(24),
                    ]);
                }

                $message = $bookedSessions < $mentorshipRequest->requestable->session_count
                    ? 'Session booked successfully. Book the remaining sessions to proceed to payment.'
                    : 'Session booked successfully. Proceed to payment using /payment/initiate/mentorship_request/' . $mentorshipRequestId;

                DB::commit();
                Log::info('Session booked for Mentorship Plan, awaiting payment', [
                    'session_id' => $session->id,
                    'mentorship_request_id' => $mentorshipRequestId,
                ]);

                return response()->json([
                    'message' => $message,
                    'session' => $session,
                ]);
            }

            DB::commit();
            Log::info('Session booked, awaiting payment', [
                'session_id' => $session->id,
            ]);

            return response()->json([
                'message' => 'Session booked successfully. Please proceed to payment using /payment/initiate/service/' . $session->id,
                'session' => $session
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to book session', [
                'coach_id' => $coachId,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
