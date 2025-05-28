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
use Ramsey\Uuid\Uuid;

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

        // Fetch booked sessions for the month (exclude Group Mentorship sessions and cancelled/completed sessions)
        $bookedSessions = NewSession::where('coach_id', $coachId)
            ->whereIn('status', ['Pending', 'Scheduled', 'Confirmed'])
            ->whereNotIn('status', ['Cancelled', 'Completed', 'Deleted'])
            ->whereBetween('date_time', [$startOfMonth, $endOfMonth])
            ->whereDoesntHave('mentorshipRequest', function ($query) {
                $query->where('requestable_type', 'App\\Models\\GroupMentorship');
            })
            ->get()
            ->groupBy(function ($session) {
                return Carbon::parse($session->date_time)->toDateString();
            });

        // Duration should be 60 minutes for all sessions
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

            // Calculate the status based on available slots
            $status = $this->calculateDayStatus($availability, $bookedSessions[$dateString] ?? collect(), $dateString, $durationMinutes);

            $dates[] = [
                'date' => $dateString,
                'day_of_week' => $dayOfWeek,
                'status' => $status,
            ];
        }

        return response()->json($dates);
    }

    /**
     * Calculate day status based on availability and booked sessions
     */
    private function calculateDayStatus($availability, $sessionsOnDate, $dateString, $durationMinutes)
    {
        // Get availability range for the day
        $startTime = Carbon::parse($availability->Start_Time, 'UTC')->setTimezone('Europe/Athens');
        $endTime = Carbon::parse($availability->End_Time, 'UTC')->setTimezone('Europe/Athens');
        
        // Generate slots for the day and check their status
        $currentTime = Carbon::parse($dateString)->setTimezone('Europe/Athens')->setTime($startTime->hour, $startTime->minute);
        $endOfAvailability = Carbon::parse($dateString)->setTimezone('Europe/Athens')->setTime($endTime->hour, $endTime->minute);
        $availableSlotsCount = 0;
        $totalSlots = 0;

        while ($currentTime->lt($endOfAvailability)) {
            $slotEnd = $currentTime->copy()->addMinutes($durationMinutes);
            if ($slotEnd->gt($endOfAvailability)) {
                break; // Don't include partial slots
            }

            $totalSlots++;

            // Check if this slot is booked
            $isSlotBooked = $sessionsOnDate->filter(function ($session) use ($currentTime, $slotEnd) {
                $sessionStart = Carbon::parse($session->date_time, 'UTC')->setTimezone('Europe/Athens');
                $sessionEnd = $sessionStart->copy()->addMinutes($session->duration);
                return $currentTime->lt($sessionEnd) && $slotEnd->gt($sessionStart);
            })->isNotEmpty();

            if (!$isSlotBooked) {
                $availableSlotsCount++;
            }

            $currentTime->addMinutes($durationMinutes);
        }

        // Determine status based on available slots count
        if ($totalSlots == 0) {
            return 'unavailable'; // No slots available at all
        } elseif ($availableSlotsCount == 0) {
            return 'booked'; // All slots are booked
        } else {
            return 'available'; // At least one slot is available
        }
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
                $start = Carbon::parse($availability->Start_Time, 'UTC')->setTimezone('Europe/Athens');
                $end = Carbon::parse($availability->End_Time, 'UTC')->setTimezone('Europe/Athens');
                $availabilityRanges[] = [
                    'start_hour' => $start->hour,
                    'start_minute' => $start->minute,
                    'end_hour' => $end->hour,
                    'end_minute' => $end->minute,
                ];
            }
        }

        // Fetch booked sessions for the date (exclude Group Mentorship sessions and cancelled/completed sessions)
        $bookedSessions = NewSession::where('coach_id', $coachId)
            ->whereIn('status', ['Pending', 'Scheduled', 'Confirmed'])
            ->whereNotIn('status', ['Cancelled', 'Completed', 'Deleted'])
            ->whereDate('date_time', $selectedDate->toDateString())
            ->whereDoesntHave('mentorshipRequest', function ($query) {
                $query->where('requestable_type', 'App\\Models\\GroupMentorship');
            })
            ->get();

        // Log the booked sessions for debugging
        Log::info('Booked Sessions for coach_id: ' . $coachId . ', date: ' . $selectedDate->toDateString(), [
            'booked_sessions' => $bookedSessions->toArray(),
        ]);

        // Generate time slots for the entire day (from 01:00 to 23:00) in EEST
        $slots = [];
        $durationMinutes = 60; // Fixed duration for all sessions

        // Start from 01:00 of the selected date in EEST
        $startOfDay = Carbon::parse($date)->setTimezone('Europe/Athens')->startOfDay()->addHour(1); // Start at 01:00 EEST
        $endOfDay = Carbon::parse($date)->setTimezone('Europe/Athens')->startOfDay()->addHours(24); // End at 00:00 next day EEST

        $currentTime = $startOfDay->copy();
        while ($currentTime->lt($endOfDay)) {
            $slotEnd = $currentTime->copy()->addMinutes($durationMinutes);
            if ($slotEnd->gt($endOfDay)) {
                break; // Don't include partial slots at the end of the day
            }

            // Use 24-hour format (H:i) in EEST
            $slotStartFormatted = $currentTime->format('H:i');
            $slotEndFormatted = $slotEnd->format('H:i');

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

                // Handle the edge case for the last slot (23:00 to 00:00)
                if ($slotEndHour == 0 && $slotEndMinute == 0) {
                    $slotEndTotalMinutes = 1440; // 24:00 in minutes
                }

                // Check if the slot falls within the availability range
                if ($currentTotalMinutes >= $rangeStartTotalMinutes && $slotEndTotalMinutes <= $rangeEndTotalMinutes) {
                    $isWithinAvailability = true;
                    break;
                }
            }

            // Default status is unavailable
            $status = 'unavailable';

            // Only check for booking status if the slot is within availability
            if ($isWithinAvailability) {
                // Check if this slot is booked (convert session times to EEST for comparison)
                $isBooked = $bookedSessions->filter(function ($session) use ($currentTime, $slotEnd) {
                    $sessionStart = Carbon::parse($session->date_time, 'UTC')->setTimezone('Europe/Athens'); // UTC to EEST
                    $sessionEnd = $sessionStart->copy()->addMinutes($session->duration);
                    return $currentTime->lt($sessionEnd) && $slotEnd->gt($sessionStart);
                })->isNotEmpty();

                // Determine the status
                if ($isBooked) {
                    $status = 'booked';
                } else {
                    $status = 'available';
                }
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
            'mentorship_request_id' => 'nullable|exists:mentorship_requests,id', // Optional for regular services
        ]);

        $service = Service::findOrFail($request->service_id);
        if ($service->coach_id !== (int)$coachId) {
            return response()->json(['message' => 'Service does not belong to this coach'], 403);
        }

        $mentorshipRequestId = $request->mentorship_request_id;
        $mentorshipRequest = $mentorshipRequestId ? MentorshipRequest::findOrFail($mentorshipRequestId) : null;

        $durationMinutes = 60; // Fixed duration for all sessions
        $startDate = Carbon::parse($request->start_date);
        $startTime = Carbon::parse($request->start_time);
        $dayOfWeek = $startDate->format('l');
        $sessionDateTime = $startDate->setTime($startTime->hour, $startTime->minute, $startTime->second);
        $slotEnd = $sessionDateTime->copy()->addMinutes($durationMinutes);

        // Check if the coach is available at this time
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

        // Check for conflicts with existing sessions (exclude Group Mentorship sessions and cancelled/completed sessions)
        $conflictingSessions = NewSession::where('coach_id', (int)$coachId)
            ->whereIn('status', ['Pending', 'Scheduled', 'Confirmed'])
            ->whereNotIn('status', ['Cancelled', 'Completed', 'Deleted'])
            ->whereDate('date_time', $sessionDateTime->toDateString())
            ->whereDoesntHave('mentorshipRequest', function ($query) {
                $query->where('requestable_type', 'App\\Models\\GroupMentorship');
            })
            ->get()
            ->filter(function ($existingSession) use ($sessionDateTime, $slotEnd) {
                $reqStart = Carbon::parse($existingSession->date_time, 'UTC')->setTimezone('Europe/Athens');
                $reqEnd = $reqStart->copy()->addMinutes($existingSession->duration);
                return $sessionDateTime->lt($reqEnd) && $slotEnd->gt($reqStart);
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

        // Determine the type of booking based on the service and mentorship request
        $isMentorshipPlanBooking = $mentorshipRequest && $mentorshipRequest->requestable_type === \App\Models\MentorshipPlan::class;

        DB::beginTransaction();
        try {
            if ($isMentorshipPlanBooking) {
                // Handle Mentorship Plan booking (4 sessions, pending payment)
                // Verify the mentorship request belongs to the authenticated user
                if ($mentorshipRequest->trainee_id !== Auth::user()->User_ID) {
                    return response()->json(['message' => 'This mentorship request does not belong to you.'], 403);
                }

                // Verify the mentorship request is accepted
                if ($mentorshipRequest->status !== 'accepted') {
                    return response()->json(['message' => 'Mentorship request must be accepted to book sessions.'], 400);
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

                $sessionsToBook = [];
                for ($i = 0; $i < $sessionCount; $i++) {
                    $sessionDate = $startDate->copy()->addWeeks($i);
                    $sessionDateTime = $sessionDate->setTime($startTime->hour, $startTime->minute, $startTime->second);
                    $slotEnd = $sessionDateTime->copy()->addMinutes($durationMinutes);

                    // Check availability for each session
                    $availability = CoachAvailability::where('coach_id', (int)$coachId)
                        ->where('Day_Of_Week', $sessionDate->format('l'))
                        ->where('Start_Time', '<=', $sessionDateTime->format('H:i:s'))
                        ->where('End_Time', '>=', $slotEnd->format('H:i:s'))
                        ->first();

                    if (!$availability) {
                        return response()->json(['message' => "Selected slot is not available on {$sessionDateTime->toDateString()}"], 400);
                    }

                    // Check for conflicts
                    $conflictingSessions = NewSession::where('coach_id', (int)$coachId)
                        ->whereIn('status', ['Pending', 'Scheduled', 'Confirmed'])
                        ->whereNotIn('status', ['Cancelled', 'Completed', 'Deleted'])
                        ->whereDate('date_time', $sessionDateTime->toDateString())
                        ->whereDoesntHave('mentorshipRequest', function ($query) {
                            $query->where('requestable_type', 'App\\Models\\GroupMentorship');
                        })
                        ->get()
                        ->filter(function ($existingSession) use ($sessionDateTime, $slotEnd) {
                            $reqStart = Carbon::parse($session->date_time, 'UTC')->setTimezone('Europe/Athens');
                            $reqEnd = $reqStart->copy()->addMinutes($session->duration);
                            return $sessionDateTime->lt($reqEnd) && $slotEnd->gt($reqStart);
                        });

                    if ($conflictingSessions->isNotEmpty()) {
                        return response()->json(['message' => "Selected slot is already reserved on {$sessionDateTime->toDateString()}"], 400);
                    }

                    $sessionsToBook[] = [
                        'date_time' => $sessionDateTime->toDateTimeString(),
                        'duration' => $durationMinutes,
                    ];
                }

                $createdSessions = [];
                foreach ($sessionsToBook as $sessionData) {
                    $session = NewSession::create([
                        'trainee_id' => Auth::user()->User_ID,
                        'coach_id' => $coachId,
                        'date_time' => $sessionData['date_time'],
                        'duration' => $sessionData['duration'],
                        'status' => 'Pending',
                        'service_id' => $service->service_id,
                        'mentorship_request_id' => $mentorshipRequestId,
                    ]);
                    $createdSessions[] = $session;
                }

                // Create a pending payment for Mentorship Plan
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
            } else {
                // Handle regular service booking (Mock Interview, LinkedIn Optimization, CV Review, Project Assessment)
                // Generate a temporary session ID to use in payment
                $tempSessionId = Uuid::uuid4()->toString();

                // Prepare session data to pass to the payment endpoint
                $sessionData = [
                    'temp_session_id' => $tempSessionId,
                    'trainee_id' => Auth::user()->User_ID,
                    'coach_id' => $coachId,
                    'service_id' => $service->service_id,
                    'date_time' => $sessionDateTime->toDateTimeString(),
                    'duration' => $durationMinutes,
                ];

                DB::commit();
                Log::info('Regular service booking initiated, awaiting payment', [
                    'temp_session_id' => $tempSessionId,
                    'service_id' => $service->service_id,
                    'trainee_id' => Auth::user()->User_ID,
                ]);

                return response()->json([
                    'message' => 'Booking initiated successfully. Proceed to payment.',
                    'payment_url' => "/api/payment/initiate/session/{$tempSessionId}",
                    'session_data' => $sessionData,
                ]);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to initiate booking', [
                'coach_id' => $coachId,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
