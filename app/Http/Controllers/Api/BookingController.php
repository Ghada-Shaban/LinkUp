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
            return response()->json(['message' => 'الخدمة دي مش تابعة للكوتش'], 403);
        }

        $startOfMonth = Carbon::parse($month)->startOfMonth();
        $endOfMonth = Carbon::parse($month)->endOfMonth();
        $currentDate = Carbon::today();

        $availabilities = CoachAvailability::where('coach_id', $coachId)->get();

        $bookedSessions = NewSession::where('coach_id', $coachId)
            ->whereIn('status', ['Pending', 'Scheduled'])
            ->whereBetween('date_time', [$startOfMonth, $endOfMonth])
            ->whereDoesntHave('mentorshipRequest', function ($query) {
                $query->where('requestable_type', GroupMentorship::class);
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

            $dayAvailabilities = $availabilities->where('Day_Of_Week', $dayOfWeek);
            if ($dayAvailabilities->isEmpty()) {
                $dates[] = [
                    'date' => $dateString,
                    'day_of_week' => $dayOfWeek,
                    'status' => 'unavailable',
                ];
                continue;
            }

            $availableSlots = [];
            foreach ($dayAvailabilities as $availability) {
                $startTime = Carbon::parse($availability->Start_Time);
                $endTime = Carbon::parse($availability->End_Time);
                $currentTime = Carbon::parse($dateString)->setTime($startTime->hour, $startTime->minute, 0);
                $endOfAvailability = Carbon::parse($dateString)->setTime($endTime->hour, $endTime->minute, 0);

                while ($currentTime->lt($endOfAvailability)) {
                    $slotEnd = $currentTime->copy()->addMinutes($durationMinutes);
                    if ($slotEnd->gt($endOfAvailability)) {
                        break;
                    }

                    $availableSlots[] = [
                        'start' => $currentTime->copy(),
                        'end' => $slotEnd->copy(),
                    ];

                    $currentTime->addMinutes($durationMinutes);
                }
            }

            $allSlotsBooked = !empty($availableSlots);
            foreach ($availableSlots as $slot) {
                $isSlotBooked = isset($bookedSessions[$dateString]) && $bookedSessions[$dateString]->contains(function ($session) use ($slot, $dateString) {
                    $sessionStart = Carbon::parse($session->date_time);
                    $sessionEnd = $sessionStart->copy()->addMinutes($session->duration);
                    $isMatch = $slot['start']->format('Y-m-d H:i') === $sessionStart->format('Y-m-d H:i')
                        && $slot['end']->format('Y-m-d H:i') === $sessionEnd->format('Y-m-d H:i');
                    Log::info('Checking slot booking status', [
                        'date' => $dateString,
                        'slot_start' => $slot['start']->toDateTimeString(),
                        'session_start' => $sessionStart->toDateTimeString(),
                        'slot_end' => $slot['end']->toDateTimeString(),
                        'session_end' => $sessionEnd->toDateTimeString(),
                        'is_booked' => $isMatch,
                    ]);
                    return $isMatch;
                });

                if (!$isSlotBooked) {
                    $allSlotsBooked = false;
                    break;
                }
            }

            $status = $allSlotsBooked && !empty($availableSlots) ? 'booked' : 'available';
            Log::info('Day status determined', [
                'date' => $dateString,
                'status' => $status,
                'available_slots_count' => count($availableSlots),
                'booked_slots_count' => isset($bookedSessions[$dateString]) ? $bookedSessions[$dateString]->count() : 0,
            ]);

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
        $request->validate([
            'date' => 'required|date',
            'service_id' => 'required|exists:services,service_id',
        ]);

        $date = $request->query('date');
        $serviceId = $request->query('service_id');
        $dayOfWeek = Carbon::parse($date)->format('l');
        $selectedDate = Carbon::parse($date);

        $service = Service::where('service_id', $serviceId)
            ->where('coach_id', $coachId)
            ->first();

        if (!$service) {
            return response()->json(['message' => 'الخدمة دي مش تابعة للكوتش'], 403);
        }

        if ($selectedDate->lt(Carbon::today())) {
            return response()->json(['message' => 'لا يمكن حجز مواعيد في تواريخ سابقة'], 400);
        }

        $availabilities = CoachAvailability::where('coach_id', $coachId)
            ->where('Day_Of_Week', $dayOfWeek)
            ->get();

        Log::info('Coach Availabilities for coach_id: ' . $coachId . ', day: ' . $dayOfWeek, [
            'availabilities' => $availabilities->toArray(),
        ]);

        $bookedSessions = NewSession::with('mentorshipRequest')
            ->where('coach_id', $coachId)
            ->whereIn('status', ['Pending', 'Scheduled'])
            ->where('date_time', '>=', $selectedDate->startOfDay()->toDateTimeString())
            ->where('date_time', '<', $selectedDate->endOfDay()->toDateTimeString())
            ->whereDoesntHave('mentorshipRequest', function ($query) {
                $query->where('requestable_type', GroupMentorship::class);
            })
            ->get();

        Log::info('Booked Sessions for coach_id: ' . $coachId . ', date: ' . $selectedDate->toDateString(), [
            'booked_sessions' => $bookedSessions->toArray(),
        ]);

        $slots = [];
        $durationMinutes = 60;
        $currentTime = $selectedDate->copy()->startOfDay();
        $endOfDay = $selectedDate->copy()->endOfDay();

        while ($currentTime->lt($endOfDay)) {
            $slotEnd = $currentTime->copy()->addMinutes($durationMinutes);

            $slotStartFormatted = $currentTime->format('H:i');
            $slotEndFormatted = $slotEnd->format('H:i');

            $isBooked = $bookedSessions->contains(function ($session) use ($currentTime) {
                $isMentorshipPlan = $session->mentorshipRequest && $session->mentorshipRequest->requestable_type === \App\Models\MentorshipPlan::class;
                $sessionStart = Carbon::parse($session->date_time);
                if (!$isMentorshipPlan) {
                    $sessionStart->addHours(3); // Apply EEST adjustment only for non-mentorship plan sessions
                }
                Log::info('Comparing slot with session', [
                    'slot_start' => $currentTime->format('Y-m-d H:i:s'),
                    'session_start_raw' => $session->date_time,
                    'session_start_adjusted' => $sessionStart->format('Y-m-d H:i:s'),
                    'is_mentorship_plan' => $isMentorshipPlan,
                ]);
                return $sessionStart->format('Y-m-d H:i') === $currentTime->format('Y-m-d H:i');
            });

            $isWithinAvailability = $availabilities->contains(function ($availability) use ($currentTime, $slotEnd, $date) {
                $availStart = Carbon::parse($availability->Start_Time);
                $availEnd = Carbon::parse($availability->End_Time);
                $availStartTime = Carbon::parse($date)->setTime($availStart->hour, $availStart->minute, 0);
                $availEndTime = Carbon::parse($date)->setTime($availEnd->hour, $availEnd->minute, 0);
                return $currentTime->gte($availStartTime) && $slotEnd->lte($availEndTime);
            });

            $status = $isBooked ? 'booked' : ($isWithinAvailability ? 'available' : 'unavailable');

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
            return response()->json(['message' => 'الخدمة دي مش تابعة للكوتش'], 403);
        }

        $mentorshipRequestId = $request->mentorship_request_id;
        $mentorshipRequest = $mentorshipRequestId ? MentorshipRequest::findOrFail($mentorshipRequestId) : null;

        // Block mentorship plan bookings in this controller
        if ($mentorshipRequest && $mentorshipRequest->requestable_type === \App\Models\MentorshipPlan::class) {
            return response()->json([
                'message' => 'حجز خطة المنتورشيب يتم من خلال /api/mentorship-plan/book'
            ], 400);
        }

        $durationMinutes = 60;
        $startDate = Carbon::parse($request->start_date);
        $startTime = Carbon::parse($request->start_time);
        $dayOfWeek = $startDate->format('l');
        $sessionDateTime = $startDate->setTime($startTime->hour, $startTime->minute, $startTime->second);
        $slotEnd = $sessionDateTime->copy()->addMinutes($durationMinutes);

        $availability = CoachAvailability::where('coach_id', (int)$coachId)
            ->where('Day_Of_Week', $dayOfWeek)
            ->where('Start_Time', '<=', $sessionDateTime->format('H:i:s'))
            ->where('End_Time', '>=', $slotEnd->format('H:i:s'))
            ->first();

        if (!$availability) {
            Log::warning('السلوت المختار مش متاح', [
                'trainee_id' => Auth::id(),
                'coach_id' => $coachId,
                'date' => $sessionDateTime->toDateString(),
                'day_of_week' => $dayOfWeek,
                'start_time' => $sessionDateTime->format('H:i'),
                'duration' => $durationMinutes,
            ]);
            return response()->json(['message' => "السلوت المختار مش متاح في {$sessionDateTime->toDateString()}"], 400);
        }

        $conflictingSessions = NewSession::where('coach_id', (int)$coachId)
            ->whereIn('status', ['Pending', 'Scheduled'])
            ->whereDate('date_time', $sessionDateTime->toDateString())
            ->whereDoesntHave('mentorshipRequest', function ($query) {
                $query->where('requestable_type', GroupMentorship::class);
            })
            ->get()
            ->filter(function ($existingSession) use ($sessionDateTime, $slotEnd) {
                $reqStart = Carbon::parse($existingSession->date_time);
                $reqEnd = $reqStart->copy()->addMinutes($existingSession->duration);
                return $sessionDateTime->equalTo($reqStart) && $slotEnd->equalTo($reqEnd);
            });

        if ($conflictingSessions->isNotEmpty()) {
            Log::warning('السلوت متعارض مع جلسات موجودة', [
                'trainee_id' => Auth::id(),
                'coach_id' => $coachId,
                'date' => $sessionDateTime->toDateString(),
                'day_of_week' => $dayOfWeek,
                'start_time' => $sessionDateTime->format('H:i'),
            ]);
            return response()->json(['message' => "السلوت المختار محجوز بالفعل في {$sessionDateTime->toDateString()}"], 400);
        }

        DB::beginTransaction();
        try {
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
            Log::info('تم بدء حجز خدمة عادية، في انتظار الدفع', [
                'temp_session_id' => $tempSessionId,
                'service_id' => $service->service_id,
                'trainee_id' => Auth::user()->User_ID,
            ]);

            return response()->json([
                'message' => 'تم بدء الحجز بنجاح. تابع الدفع.',
                'payment_url' => "/api/payment/initiate/session/{$tempSessionId}",
                'session_data' => $sessionData,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('فشل بدء الحجز', [
                'coach_id' => $coachId,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
