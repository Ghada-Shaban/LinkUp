<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CoachAvailability;
use App\Models\NewSession;
use App\Models\Service;
use App\Models\MentorshipRequest;
use App\Models\GroupMentorship;
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

        // Fetch booked sessions for the month (exclude Group Mentorship sessions)
        $bookedSessions = NewSession::where('coach_id', $coachId)
            ->whereIn('status', ['Pending', 'Scheduled'])
            ->whereBetween('date_time', [$startOfMonth, $endOfMonth])
            ->whereDoesntHave('mentorshipRequest', function ($query) {
                $query->where('requestable_type', 'App\\Models\\GroupMentorship');
            })
            ->get()
            ->groupBy(function ($session) {
                return Carbon::parse($session->date_time)->toDateString();
            });

        // Duration should be 60 minutes for Mentorship Plan sessions
        $durationMinutes = 60;

        // Generate list of dates for the month
        $dates = [];
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

        // Log the availabilities for debugging
        Log::info('Coach Availabilities for coach_id: ' . $coachId . ', day: ' . $dayOfWeek, [
            'availabilities' => $availabilities->toArray(),
        ]);

        if ($availabilities->isEmpty()) {
            $availabilityRanges = [];
        } else {
            // Collect all availability ranges for the day (only hours and minutes)
            $availabilityRanges = [];
            foreach ($availabilities as $availability) {
                $start = Carbon::parse($availability->Start_Time);
                $end = Carbon::parse($availability->End_Time);
                $availabilityRanges[] = [
                    'start_hour' => $start->hour,
                    'start_minute' => $start->minute,
                    'end_hour' => $end->hour,
                    'end_minute' => $end->minute,
                ];
            }
        }

        // Fetch booked sessions for the date (exclude Group Mentorship sessions)
        $bookedSessions = NewSession::where('coach_id', $coachId)
            ->whereIn('status', ['Pending', 'Scheduled'])
            ->whereDate('date_time', $selectedDate->toDateString())
            ->whereDoesntHave('mentorshipRequest', function ($query) {
                $query->where('requestable_type', 'App\\Models\\GroupMentorship');
            })
            ->get();

        // Log the booked sessions for debugging
        Log::info('Booked Sessions for coach_id: ' . $coachId . ', date: ' . $selectedDate->toDateString(), [
            'booked_sessions' => $bookedSessions->toArray(),
        ]);

        // Generate time slots for the entire day (from 12:00 AM to 11:59 PM)
        $slots = [];
        $durationMinutes = 60; // Fixed duration for Mentorship Plan sessions

        // Start from 12:00 AM of the selected date
        $startOfDay = Carbon::parse($date)->startOfDay();
        $endOfDay = Carbon::parse($date)->endOfDay();

        $currentTime = $startOfDay->copy();
        while ($currentTime->lt($endOfDay)) {
            $slotEnd = $currentTime->copy()->addMinutes($durationMinutes);
            if ($slotEnd->gt($endOfDay)) {
                break; // Don't include partial slots at the end of the day
            }

            $slotStartFormatted = $currentTime->format('h:i A');
            $slotEndFormatted = $slotEnd->format('h:i A');

            // Check if this slot is booked
            $isBooked = $bookedSessions->filter(function ($session) use ($currentTime, $slotEnd) {
                $sessionStart = Carbon::parse($session->date_time);
                $sessionEnd = $sessionStart->copy()->addMinutes($session->duration_minutes);
                return $currentTime->lt($sessionEnd) && $slotEnd->gt($sessionStart);
            })->isNotEmpty();

            // Check if this slot falls within the coach's availability (compare hours and minutes only)
            $isWithinAvailability = false;
            foreach ($availabilityRanges as $range) {
                $currentHour = $currentTime->hour;
                $currentMinute = $currentTime->minute;
                $slotEndHour = $slotEnd->hour;
                $slotEndMinute = $slotEnd->minute;

                // Convert times to minutes for easier comparison
                $currentTotalMinutes = ($currentHour * 60) + $currentMinute;
                $slotEndTotalMinutes = ($slotEndHour * 60) + $slotEndMinute;
                $rangeStartTotalMinutes = ($range['start_hour'] * 60) + $range['start_minute'];
                $rangeEndTotalMinutes = ($range['end_hour'] * 60) + $range['end_minute'];

                if ($currentTotalMinutes >= $rangeStartTotalMinutes && $slotEndTotalMinutes <= $rangeEndTotalMinutes) {
                    $isWithinAvailability = true;
                    break;
                }
            }

            // Determine the status
            $status = 'unavailable'; // Default status
            if ($isBooked) {
                $status = 'booked';
            } elseif ($isWithinAvailability) {
                $status = 'available';
            }

            $slots[] = [
                'start_time' => $slotStartFormatted,
                'end_time' => $slotEndFormatted,
                'status' => $status,
            ];

            $currentTime->addMinutes($durationMinutes);
        }

        return response()->json($slots);
    }

    public function bookService(Request $request, $coachId)
    {
        // Validate the request
        $request->validate([
            'service_id' => 'required|exists:services,service_id',
            'start_time' => 'required|date_format:H:i:s',
            'start_date' => 'required|date|after:now',
            'mentorship_request_id' => 'required|exists:mentorship_requests,id',
        ]);

        $service = Service::findOrFail($request->service_id);
        if ($service->coach_id !== (int)$coachId) {
            return response()->json(['message' => 'Service does not belong to this coach'], 403);
        }

        $mentorshipRequestId = $request->mentorship_request_id;
        $mentorshipRequest = MentorshipRequest::findOrFail($mentorshipRequestId);

        // Verify the mentorship request belongs to the authenticated user
        if ($mentorshipRequest->trainee_id !== Auth::user()->User_ID) {
            return response()->json(['message' => 'This mentorship request does not belong to you.'], 403);
        }

        // Verify the mentorship request is accepted
        if ($mentorshipRequest->status !== 'accepted') {
            return response()->json(['message' => 'Mentorship request must be accepted to book sessions.'], 400);
        }

        // Verify the mentorship request is for a Mentorship Plan
        if ($mentorshipRequest->requestable_type !== \App\Models\MentorshipPlan::class) {
            return response()->json(['message' => 'Mentorship request must be for a Mentorship Plan to book sessions this way.'], 400);
        }

        // Verify the service matches the mentorship request
        if ($mentorshipRequest->requestable->service_id !== $service->service_id) {
            return response()->json(['message' => 'Service does not match the mentorship request.'], 400);
        }

        // Check the number of sessions to book (fixed at 4 for Mentorship Plan)
        $sessionCount = 4;
        $bookedSessionsCount = NewSession::where('mentorship_request_id', $mentorshipRequestId)->count();
        $remainingSessions = $sessionCount - $bookedSessionsCount;

        if ($remainingSessions <= 0) {
            return response()->json(['message' => 'You have already booked the maximum number of sessions for this Mentorship Plan.'], 400);
        }

        if ($remainingSessions < $sessionCount) {
            return response()->json(['message' => 'Mentorship Plan requires exactly 4 sessions to be booked at once.'], 400);
        }

        $startDate = Carbon::parse($request->start_date);
        $startTime = Carbon::parse($request->start_time);
        $durationMinutes = 60;

        $sessionsToBook = [];
        $dayOfWeek = $startDate->format('l');

        // Book 4 sessions (one per week)
        for ($i = 0; $i < $sessionCount; $i++) {
            $sessionDate = $startDate->copy()->addWeeks($i);
            $sessionDateTime = $sessionDate->setTime($startTime->hour, $startTime->minute, $startTime->second);
            $slotEnd = $sessionDateTime->copy()->addMinutes($durationMinutes);

            // Check if the coach is available at Mississippi time
            $availability = CoachAvailability::where('coach_id', (int)$coachId)
                ->where('Day_Of_Week', $dayOfWeek)
                ->where('Start_Time', '<=', $sessionDateTime->format('H:i:s'))
                ->where('End_Time', '>=', $slotEnd->format('H:i:s'))
                ->first();

            if (!$availability) {
                Log::warning('Selected slot is not available', [
                    'trainee_id' => Auth::id(),
                    'coach_id' => $coachId,
                    'date' => $sessionDateTime->toDateString(),
                    'day_of_week' => $dayOfWeek,
                    'start_time' => $sessionDateTime->format('H:i'),
                    'duration' => $durationMinutes,
                ]);
                return response()->json(['message' => "Selected slot is not available on {$sessionDateTime->toDateString()}"], 400);
            }

            // Check for conflicts with existing sessions (exclude Group Mentorship sessions)
            $conflictingSessions = NewSession::where('coach_id', (int)$coachId)
                ->whereIn('status', ['Pending', 'Scheduled'])
                ->whereDate('date_time', $sessionDateTime->toDateString())
                ->whereDoesntHave('mentorshipRequest', function ($query) {
                    $query->where('requestable_type', 'App\\Models\\GroupMentorship');
                })
                ->get()
                ->filter(function ($existingSession) use ($sessionDateTime, $slotEnd) {
                    $reqStart = Carbon::parse($existingSession->date_time);
                    $reqEnd = $reqStart->copy()->addMinutes($existingSession->duration_minutes);
                    return $sessionDateTime < $reqEnd && $slotEnd > $reqStart;
                });

            if ($conflictingSessions->isNotEmpty()) {
                Log::warning('Slot conflicts with existing sessions', [
                    'trainee_id' => Auth::id(),
                    'coach_id' => $coachId,
                    'date' => $sessionDateTime->toDateString(),
                    'day_of_week' => $dayOfWeek,
                    'start_time' => $sessionDateTime->format('H:i'),
                ]);
                return response()->json(['message' => "Selected slot is already reserved on {$sessionDateTime->toDateString()}"], 400);
            }

            $sessionsToBook[] = [
                'date_time' => $sessionDateTime->toDateTimeString(),
                'duration_minutes' => $durationMinutes,
            ];
        }

        DB::beginTransaction();
        try {
            $createdSessions = [];
            foreach ($sessionsToBook as $sessionData) {
                $session = NewSession::create([
                    'trainee_id' => Auth::user()->User_ID,
                    'coach_id' => $coachId,
                    'date_time' => $sessionData['date_time'],
                    'duration_minutes' => $sessionData['duration_minutes'],
                    'status' => 'Pending',
                    'service_id' => $service->service_id,
                    'mentorship_request_id' => $mentorshipRequestId,
                ]);
                $createdSessions[] = $session;
            }

            // Create a pending payment since all 4 sessions are booked
            \App\Models\PendingPayment::create([
                'mentorship_request_id' => $mentorshipRequestId,
                'payment_due_at' => now()->addHours(24),
            ]);

            DB::commit();
            Log::info('All sessions booked for Mentorship Plan, awaiting payment', [
                'mentorship_request_id' => $mentorshipRequestId,
                'sessions' => $createdSessions,
            ]);

            return response()->json([
                'message' => 'All sessions booked successfully. Proceed to payment using /api/payment/initiate/mentorship_request/' . $mentorshipRequestId,
                'sessions' => $createdSessions,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to book sessions', [
                'coach_id' => $coachId,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
