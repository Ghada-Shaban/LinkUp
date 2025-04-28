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
            ->whereBetween('date_time', [$startOfMonth, $endOfMonth])
            ->get()
            ->groupBy(function ($session) {
                return Carbon::parse($session->date_time)->toDateString();
            });

        // Generate list of dates for the month
        $dates = [];
        $duration = 60; // تغيير duration_minutes إلى duration وتحديده بـ 60 دقيقة

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
                $totalSlots = $totalMinutesAvailable / $duration;

                $bookedMinutes = 0;
                foreach ($sessionsOnDate as $session) {
                    $bookedMinutes += $session->duration; // تغيير duration_minutes إلى duration
                }

                $bookedSlots = $bookedMinutes / $duration;
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
            ->whereDate('date_time', $selectedDate->toDateString())
            ->get();

        // Generate time slots (60-minute intervals)
        $slots = [];
        $duration = 60; // تغيير duration_minutes إلى duration وتحديده بـ 60 دقيقة

        foreach ($availabilities as $availability) {
            $startTime = Carbon::parse($availability->Start_Time);
            $endTime = Carbon::parse($availability->End_Time);

            while ($startTime->lt($endTime)) {
                $slotEnd = $startTime->copy()->addMinutes($duration);
                if ($slotEnd->gt($endTime)) {
                    break; // Don't include partial slots
                }

                $slotStartFormatted = $startTime->format('H:i:s');
                $slotEndFormatted = $slotEnd->format('H:i:s');

                // Check if this slot is booked
                $isBooked = $bookedSessions->filter(function ($session) use ($startTime, $slotEnd) {
                    $sessionStart = Carbon::parse($session->date_time);
                    $sessionEnd = $sessionStart->copy()->addMinutes($session->duration); // تغيير duration_minutes إلى duration
                    return $startTime->lt($sessionEnd) && $slotEnd->gt($sessionStart);
                })->isNotEmpty();

                $slots[] = [
                    'start_time' => $slotStartFormatted,
                    'end_time' => $slotEndFormatted,
                    'status' => $isBooked ? 'unavailable' : 'available',
                ];

                $startTime->addMinutes($duration);
            }
        }

        return response()->json($slots);
    }

    public function bookService(Request $request, $coachId)
    {
        $request->validate([
            'service_id' => 'required|exists:services,service_id',
            'start_time' => 'required|date_format:H:i:s',
            'start_date' => 'required|date|after:now',
            'weeks' => 'required|integer|min:1',
            'mentorship_request_id' => 'required|exists:mentorship_requests,id',
        ]);

        $service = Service::findOrFail($request->service_id);
        if ($service->coach_id !== (int)$coachId) {
            return response()->json(['message' => 'Service does not belong to this coach'], 403);
        }

        $mentorshipRequestId = $request->mentorship_request_id;
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
        $bookedSessionsCount = NewSession::where('mentorship_request_id', $mentorshipRequestId)->count();
        $remainingSessions = $sessionCount - $bookedSessionsCount;

        if ($remainingSessions <= 0) {
            return response()->json(['message' => 'You have already booked the maximum number of sessions for this Mentorship Plan.'], 400);
        }

        $weeks = $request->weeks;
        if ($weeks > $remainingSessions) {
            return response()->json(['message' => "You can only book $remainingSessions more session(s) for this Mentorship Plan."], 400);
        }

        $startDate = Carbon::parse($request->start_date);
        $startTime = Carbon::parse($request->start_time);
        $duration = 60; // تحديد الـ duration بـ 60 دقيقة دايمًا

        $sessionsToBook = [];
        $dayOfWeek = $startDate->format('l');

        // Generate session times for the specified number of weeks
        for ($i = 0; $i < $weeks; $i++) {
            $sessionDate = $startDate->copy()->addWeeks($i);
            $sessionDateTime = $sessionDate->setTime($startTime->hour, $startTime->minute, $startTime->second);
            $slotEnd = $sessionDateTime->copy()->addMinutes($duration);

            // Check Coach Availability for this date
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
                    'duration' => $duration,
                ]);
                return response()->json(['message' => "Selected slot is not available on {$sessionDateTime->toDateString()}"], 400);
            }

            // Check for conflicts on this date
            $conflictingSessions = NewSession::where('coach_id', (int)$coachId)
                ->whereIn('status', ['pending', 'upcoming'])
                ->whereDate('date_time', $sessionDateTime->toDateString())
                ->get()
                ->filter(function ($existingSession) use ($sessionDateTime, $slotEnd) {
                    $reqStart = Carbon::parse($existingSession->date_time);
                    $reqEnd = $reqStart->copy()->addMinutes($existingSession->duration); // تغيير duration_minutes إلى duration
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
                'duration' => $duration,
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
                    'duration' => $sessionData['duration'], // تغيير duration_minutes إلى duration
                    'status' => 'pending',
                    'service_id' => $service->service_id,
                    'mentorship_request_id' => $mentorshipRequestId,
                ]);
                $createdSessions[] = $session;
            }

            // Check if all sessions for the MentorshipPlan are booked
            $bookedSessionsCount = NewSession::where('mentorship_request_id', $mentorshipRequestId)->count();
            if ($bookedSessionsCount == $mentorshipRequest->requestable->session_count) {
                \App\Models\PendingPayment::create([
                    'mentorship_request_id' => $mentorshipRequestId,
                    'trainee_id' => Auth::user()->User_ID,
                    'coach_id' => $coachId,
                    'payment_due_at' => now()->addHours(24),
                ]);
            }

            $message = $bookedSessionsCount < $mentorshipRequest->requestable->session_count
                ? 'Sessions booked successfully. Book the remaining sessions to proceed to payment.'
                : 'Sessions booked successfully. Proceed to payment using /payment/initiate/mentorship_request/' . $mentorshipRequestId;

            DB::commit();
            Log::info('Sessions booked for Mentorship Plan, awaiting payment', [
                'mentorship_request_id' => $mentorshipRequestId,
                'sessions' => $createdSessions,
            ]);

            return response()->json([
                'message' => $message,
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
