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
        $request->validate([
            'service_id' => 'required|exists:services,service_id',
            'month' => 'required|date_format:Y-m',
        ]);

        $serviceId = $request->query('service_id');
        $month = $request->query('month');

        $service = Service::where('service_id', $serviceId)
            ->where('coach_id', $coachId)
            ->first();

        if (!$service) {
            return response()->json(['message' => 'Service does not belong to this coach'], 403);
        }

        $startOfMonth = Carbon::parse($month);
        $endOfMonth = Carbon::parse($month)->endOfMonth();
        $currentDate = Carbon::today();

        $availabilities = CoachAvailability::where('coach_id', $coachId)->get();

        $bookedSessions = NewSession::where('coach_id', $coachId)
            ->whereIn('status', ['Pending', 'Scheduled', 'Completed'])
            ->whereBetween('date_time', [$startOfMonth, $endOfMonth])
            ->whereDoesntHave('mentorshipRequest', function ($query) {
                $query->where('requestable_type', 'App\\Models\\GroupMentorship');
            })
            ->get()
            ->groupBy(function ($session) {
                return Carbon::parse($session->date_time)->toDateString();
            });

        $durationMinutes = 60;

        $dates = [];
        for ($date = $startOfMonth->copy(); $date->lte($endOfMonth); $date->addDay()) {
            $dayOfWeek = $date->format('l');
            $dateString = $date->toDateString();

            if ($date->lt($currentDate)) {
                $dates[] = [
                    'date' => $dateString,
                    'day_of_week' => $dayOfWeek,
                    'status' => 'unavailable',
                ];
                continue;
            }

            $availability = $availabilities->firstWhere('Day_Of_Week', $dayOfWeek);
            if (!$availability) {
                $dates[] = [
                    'date' => $dateString,
                    'day_of_week' => $dayOfWeek,
                    'status' => 'unavailable',
                ];
                continue;
            }

            $hasAvailableSlots = true;
            $availableSlots = $this->getAvailableSlotsForDate($dateString, $coachId, $serviceId);
            if ($availableSlots->isEmpty() || $availableSlots->every(function ($slot) {
                return $slot['status'] === 'booked' || $slot['status'] === 'unavailable';
            })) {
                $hasAvailableSlots = false;
            }

            $status = $hasAvailableSlots ? 'available' : 'booked';
            $dates[] = [
                'date' => $dateString,
                'day_of_week' => $dayOfWeek,
                'status' => $status,
            ];
        }

        return response()->json($dates);
    }

    private function getAvailableSlotsForDate($date, $coachId, $serviceId)
    {
        $dayOfWeek = Carbon::parse($date)->format('l');
        $selectedDate = Carbon::parse($date);

        $availabilities = CoachAvailability::where('coach_id', $coachId)
            ->where('Day_Of_Week', $dayOfWeek)
            ->get();

        $availabilityRanges = [];
        foreach ($availabilities as $availability) {
            $start = Carbon::parse($availability->Start_Time);
            $end = Carbon::parse($availability->End_Time);
            $availabilityRanges[] = [
                'start' => $start->hour * 60 + $start->minute,
                'end' => $end->hour * 60 + $end->minute,
            ];
        }

        $bookedSessions = NewSession::where('coach_id', $coachId)
            ->whereIn('status', ['Pending', 'Scheduled', 'Completed'])
            ->whereDate('date_time', $selectedDate->toDateString())
            ->whereDoesntHave('mentorshipRequest', function ($query) {
                $query->where('requestable_type', 'App\\Models\\GroupMentorship');
            })
            ->get();

        $slots = [];
        $durationMinutes = 60;

        $startOfDay = $selectedDate->copy()->startOfDay();
        $endOfDay = $selectedDate->copy()->endOfDay();

        $currentTime = $startOfDay->copy();
        while ($currentTime->lt($endOfDay)) {
            $slotEnd = $currentTime->copy()->addMinutes($durationMinutes);
            if ($slotEnd->gt($endOfDay)) {
                break;
            }

            $slotStartFormatted = $currentTime->format('H:i');
            $slotEndFormatted = $slotEnd->format('H:i');

            $slotStartWithDate = Carbon::parse($selectedDate->toDateString() . ' ' . $slotStartFormatted);
            $slotEndWithDate = Carbon::parse($selectedDate->toDateString() . ' ' . $slotEndFormatted);

            $currentMinutes = $currentTime->hour * 60 + $currentTime->minute;
            $slotEndMinutes = $slotEnd->hour * 60 + $slotEnd->minute;

            $isWithinAvailability = false;
            foreach ($availabilityRanges as $range) {
                if ($currentMinutes >= $range['start'] && $slotEndMinutes <= $range['end']) {
                    $isWithinAvailability = true;
                    break;
                }
            }

            $status = 'unavailable';
            if ($isWithinAvailability) {
                $isBooked = $bookedSessions->filter(function ($session) use ($slotStartWithDate, $slotEndWithDate) {
                    $sessionStart = Carbon::parse($session->date_time);
                    $sessionEnd = $sessionStart->copy()->addMinutes($session->duration);
                    return $slotStartWithDate->lt($sessionEnd) && $slotEndWithDate->gt($sessionStart);
                })->isNotEmpty();

                $status = $isBooked ? 'booked' : 'available';
            }

            $slots[] = [
                'start_time' => $slotStartFormatted,
                'end_time' => $slotEndFormatted,
                'status' => $status,
            ];

            $currentTime->addMinutes($durationMinutes);
        }

        return collect($slots);
    }

    public function getAvailableSlots(Request $request, $coachId)
    {
        $request->validate([
            'date' => 'required|date',
            'service_id' => 'required|exists:services,service_id',
        ]);

        $date = $request->query('date');
        $serviceId = $request->query('service_id');
        $dayOfWeek = Carbon::parse($date)->format('l');

        $service = Service::where('service_id', $serviceId)
            ->where('coach_id', $coachId)
            ->first();

        if (!$service) {
            return response()->json(['message' => 'Service does not belong to this coach'], 403);
        }

        $selectedDate = Carbon::parse($date);
        if ($selectedDate->lt(Carbon::today())) {
            return response()->json(['message' => 'Cannot book slots for past dates'], 400);
        }

        $availabilities = CoachAvailability::where('coach_id', $coachId)
            ->where('Day_Of_Week', $dayOfWeek)
            ->get();

        Log::info('Coach Availabilities for coach_id: ' . $coachId . ', day: ' . $dayOfWeek, [
            'availabilities' => $availabilities->toArray(),
        ]);

        $availabilityRanges = [];
        foreach ($availabilities as $availability) {
            $start = Carbon::parse($availability->Start_Time);
            $end = Carbon::parse($availability->End_Time);
            $availabilityRanges[] = [
                'start' => $start->hour * 60 + $start->minute,
                'end' => $end->hour * 60 + $end->minute,
            ];
        }

        $bookedSessions = NewSession::where('coach_id', $coachId)
            ->whereIn('status', ['Pending', 'Scheduled', 'Completed'])
            ->whereDate('date_time', $selectedDate->toDateString())
            ->whereDoesntHave('mentorshipRequest', function ($query) {
                $query->where('requestable_type', 'App\\Models\\GroupMentorship');
            })
            ->get();

        Log::info('Booked Sessions for coach_id: ' . $coachId . ', date: ' . $selectedDate->toDateString(), [
            'booked_sessions' => $bookedSessions->toArray(),
        ]);

        $slots = [];
        $durationMinutes = 60;

        $startOfDay = $selectedDate->copy()->startOfDay();
        $endOfDay = $selectedDate->copy()->endOfDay();

        $currentTime = $startOfDay->copy();
        while ($currentTime->lt($endOfDay)) {
            $slotEnd = $currentTime->copy()->addMinutes($durationMinutes);
            if ($slotEnd->gt($endOfDay)) {
                break;
            }

            $slotStartFormatted = $currentTime->format('H:i');
            $slotEndFormatted = $slotEnd->format('H:i');

            $slotStartWithDate = Carbon::parse($selectedDate->toDateString() . ' ' . $slotStartFormatted);
            $slotEndWithDate = Carbon::parse($selectedDate->toDateString() . ' ' . $slotEndFormatted);

            $currentMinutes = $currentTime->hour * 60 + $currentTime->minute;
            $slotEndMinutes = $slotEnd->hour * 60 + $slotEnd->minute;

            $isWithinAvailability = false;
            foreach ($availabilityRanges as $range) {
                if ($currentMinutes >= $range['start'] && $slotEndMinutes <= $range['end']) {
                    $isWithinAvailability = true;
                    break;
                }
            }

            $status = 'unavailable';
            if ($isWithinAvailability) {
                $isBooked = $bookedSessions->filter(function ($session) use ($slotStartWithDate, $slotEndWithDate) {
                    $sessionStart = Carbon::parse($session->date_time);
                    $sessionEnd = $sessionStart->copy()->addMinutes($session->duration);
                    return $slotStartWithDate->lt($sessionEnd) && $slotEndWithDate->gt($sessionStart);
                })->isNotEmpty();

                $status = $isBooked ? 'booked' : 'available';
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
        $request->validate([
            'service_id' => 'required|exists:services,service_id',
            'start_time' => 'required|date_format:H:i:s',
            'start_date' => 'required|date|after:now',
            'mentorship_request_id' => 'nullable|exists:mentorship_requests,id',
        ]);

        $service = Service::findOrFail($request->service_id);
        if ($service->coach_id !== (int)$coachId) {
            return response()->json(['message' => 'Service does not belong to this coach'], 403);
        }

        $mentorshipRequestId = $request->mentorship_request_id;
        $mentorshipRequest = $mentorshipRequestId ? MentorshipRequest::findOrFail($mentorshipRequestId) : null;

        $durationMinutes = 60;
        $startDate = Carbon::parse($request->start_date);
        $startTime = Carbon::parse($request->start_time);
        $dayOfWeek = $startDate->format('l');
        $sessionDateTime = $startDate->setTime($startTime->hour, $startTime->minute, 0);
        $slotEnd = $sessionDateTime->copy()->addMinutes($durationMinutes);

        $availability = CoachAvailability::where('coach_id', (int)$coachId)
            ->where('Day_Of_Week', $dayOfWeek)
            ->get()
            ->first(function ($avail) use ($sessionDateTime, $slotEnd) {
                $start = Carbon::parse($avail->Start_Time);
                $end = Carbon::parse($avail->End_Time);
                $slotStartMinutes = ($sessionDateTime->hour * 60) + $sessionDateTime->minute;
                $slotEndMinutes = ($slotEnd->hour * 60) + $slotEnd->minute;
                $startMinutes = ($start->hour * 60) + $start->minute;
                $endMinutes = ($end->hour * 60) + $end->minute;

                if ($slotEnd->hour == 0 && $slotEnd->minute == 0) {
                    $slotEndMinutes = 1440;
                }

                return $slotStartMinutes >= $startMinutes && $slotEndMinutes <= $endMinutes;
            });

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

        $conflictingSessions = NewSession::where('coach_id', (int)$coachId)
            ->whereIn('status', ['Pending', 'Scheduled', 'Completed'])
            ->whereDate('date_time', $sessionDateTime->toDateString())
            ->whereDoesntHave('mentorshipRequest', function ($query) {
                $query->where('requestable_type', 'App\\Models\\GroupMentorship');
            })
            ->get()
            ->filter(function ($existingSession) use ($sessionDateTime, $slotEnd) {
                $reqStart = Carbon::parse($existingSession->date_time);
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

        $isMentorshipPlanBooking = $mentorshipRequest && $mentorshipRequest->requestable_type === \App\Models\MentorshipPlan::class;

        DB::beginTransaction();
        try {
            if ($isMentorshipPlanBooking) {
                if ($mentorshipRequest->trainee_id !== Auth::user()->User_ID) {
                    return response()->json(['message' => 'This mentorship request does not belong to you.'], 403);
                }

                if ($mentorshipRequest->status !== 'accepted') {
                    return response()->json(['message' => 'Mentorship request must be accepted to book sessions.'], 400);
                }

                if ($mentorshipRequest->requestable->service_id !== $service->service_id) {
                    return response()->json(['message' => 'Service does not match the mentorship request.'], 400);
                }

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
                    $sessionDateTime = $sessionDate->setTime($startTime->hour, $startTime->minute, 0);
                    $slotEnd = $sessionDateTime->copy()->addMinutes($durationMinutes);

                    $availability = CoachAvailability::where('coach_id', (int)$coachId)
                        ->where('Day_Of_Week', $sessionDate->format('l'))
                        ->get()
                        ->first(function ($avail) use ($sessionDateTime, $slotEnd) {
                            $start = Carbon::parse($avail->Start_Time);
                            $end = Carbon::parse($avail->End_Time);
                            $slotStartMinutes = ($sessionDateTime->hour * 60) + $sessionDateTime->minute;
                            $slotEndMinutes = ($slotEnd->hour * 60) + $slotEnd->minute;
                            $startMinutes = ($start->hour * 60) + $start->minute;
                            $endMinutes = ($end->hour * 60) + $end->minute;

                            if ($slotEnd->hour == 0 && $slotEnd->minute == 0) {
                                $slotEndMinutes = 1440;
                            }

                            return $slotStartMinutes >= $startMinutes && $slotEndMinutes <= $endMinutes;
                        });

                    if (!$availability) {
                        return response()->json(['message' => "Selected slot is not available on {$sessionDateTime->toDateString()}"], 400);
                    }

                    $conflictingSessions = NewSession::where('coach_id', (int)$coachId)
                        ->whereIn('status', ['Pending', 'Scheduled', 'Completed'])
                        ->whereDate('date_time', $sessionDateTime->toDateString())
                        ->whereDoesntHave('mentorshipRequest', function ($query) {
                            $query->where('requestable_type', 'App\\Models\\GroupMentorship');
                        })
                        ->get()
                        ->filter(function ($existingSession) use ($sessionDateTime, $slotEnd) {
                            $reqStart = Carbon::parse($existingSession->date_time);
                            $reqEnd = $reqStart->copy()->addMinutes($existingSession->duration);
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
                $tempSessionId = Uuid::uuid4()->toString();

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
