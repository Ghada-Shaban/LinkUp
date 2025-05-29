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
    // دالة getAvailableDates بدون تغيير
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
            return response()->json(['message' => 'الخدمة غير متاحة لهذا المدرب'], 403);
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
                        'session_start_date' => $sessionStart->toDateTimeString(),
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
                'booked_slots_count' => isset($bookedSessions[$dateString]) ? $bookedSessions->get($dateString)->count() : 0,
            ]);

            $dates[] = [
                'date' => $dateString,
                'day_of_week' => $dayOfWeek,
                'status' => $status,
            ];
        }

        return response()->json($dates);
    }

    // دالة getAvailableSlots مع التعديلات
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
            return response()->json(['message' => 'الخدمة غير متاحة لهذا المدرب'], 403);
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

            // تنظيف النصوص لضمان UTF-8
            $slotStartFormatted = mb_convert_encoding($currentTime->format('H:i'), 'UTF-8', 'UTF-8');
            $slotEndFormatted = mb_convert_encoding($slotEnd->format('H:i'), 'UTF-8', 'UTF-8');

            $isBooked = $bookedSessions->contains(function ($session) use ($currentTime) {
                $isMentorshipPlan = $session->mentorshipRequest && $session->mentorshipRequest->requestable_type === \App\Models\MentorshipPlan::class;
                $sessionStart = Carbon::parse($session->date_time);
                if ($isMentorshipPlan) {
                    $sessionStart->addHours(3); // Adjust to EEST for Mentorship Plan
                }
                Log::info('Comparing slot with session', [
                    'slot_start' => mb_convert_encoding($currentTime->format('Y-m-d H:i:s'), 'UTF-8', 'UTF-8'),
                    'session_start_raw' => mb_convert_encoding($session->date_time, 'UTF-8', 'UTF-8'),
                    'session_start_adjusted' => mb_convert_encoding($sessionStart->format('Y-m-d H:i:s'), 'UTF-8', 'UTF-8'),
                    'is_mentorship_plan' => $isMentorshipPlan,
                ]);
                return $sessionStart->format('Y-m-d H:i') === $currentTime->format('Y-m-d H:I');
            });

            $isWithinAvailability = $availabilities->contains(function ($availability) use ($currentTime, $slotEnd, $date) {
                $availStart = Carbon::parse($availability->StartTime);
                $availEnd = Carbon::parse($availability->EndTime);
                $availStartTime = Carbon::parse($date)->setTime($availStart->hour, $availStart->minute, $0);
                $availEndTime = Carbon::parse($date)->setTime($availEnd->hour, $availEnd->minute, $0);
                return $currentTime->gte($availStartTime) && $slotEnd->lte($availEndTime);
            });

            $status = $isBooked ? 'booked' : ($isWithinAvailability ? 'available' : 'unavailable');

            $slots[] = [
                'start_time' => $slotStartFormatted,
                'end_time' => $slotEndFormatted,
                'status' => mb_convert_encoding($status, 'UTF-8', 'UTF-8'),
            ];

            $currentTime->addMinutes($durationMinutes);
        }

        // Wrap response to handle encoding
        return response()->json($slots);
    }

    // دالة bookService بدون تغيير
    public function bookService(Request $request, $coachId)
    {
        $request->validate([
            'service_id' => 'required|exists:services,service_id',
            'start_time' => 'required|date_format:H:i:s',
            'start_date' => 'required|date|after:today',
            'mentorship_request_id' => 'nullable|exists:mentorship_requests,id',
        ]);

        $service = Service::findOrFail($request->service_id);
        if ($service->coach_id !== (int)$coachId) {
            return response()->json(['message' => 'الخدمة غير متاحة لهذا المدرب'], 403);
        }

        $mentorshipRequestId = $request->mentorship_request_id;
        $mentorshipRequest = $mentorshipRequestId ? MentorshipRequest::findOrFail($mentorshipRequestId) : null;

        $durationMinutes = 60;
        $startDate = Carbon::parse($request->start_date);
        $startTime = Carbon::parse($request->start_time);
        $dayOfWeek = $startDate->format('l');
        $sessionDateTime = $startDate->setTime($startTime->hour, $startTime->minute, $startTime->second);
        $slotEnd = $sessionDateTime->copy()->addMinutes($durationMinutes);

        Log::info('Initial session date time', [
            'start_date' => $request->start_date,
            'start_time' => $request->start_time,
            'session_date_time' => $sessionDateTime->toDateTimeString(),
            'timezone' => $sessionDateTime->getTimezone()->getName(),
        ]);

        $availability = CoachAvailability::where('coach_id', (int)$coachId)
            ->where('DayOf_Week', $dayOfWeek)
            ->where('Start_Time', '<=', $sessionDateTime->format('H:i:s'))
            ->where('End_Time', '>=', $slotEnd->toDateTimeString('H:i:s'))
            ->first();

        if (!$availability) {
            Log::warning('السلوت المختار مش متاح', [
                'trainee_id' => Auth::id(),
                'coach_id' => $coachId,
                'date' => $sessionDateTime->toDateTimeString(),
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
            ->filter(function($existingSession) use ($sessionDateTime, $slotEnd) {
                $reqStart = Carbon::parse($existingSession->date_time);
                $reqEnd = $reqStart->copy()->addMinutes($existingSession->duration);
                return $sessionDateTime->equalTo($reqStart) && $slotEnd->equalTo($reqEnd);
            });

        if ($conflictingSessions->isNotEmpty()) {
            Log::warning('السلوت المختار متعارض مع جلسة موجودة', [
                'trainee_id' => Auth::id(),
                'coach_id' => $coachId,
                'date' => $sessionDateTime->toDateString(),
                'day_of_week' => $dayOfWeek,
                'start_time' => $sessionDateTime->format('H:i'),
            ]);
            return response()->json(['message' => "السلوت المختار محجوز بالفعل في {$sessionDateTime->toDateString()}"], 400);
        }

        $isMentorshipPlanBooking = $mentorshipRequest && $mentorshipRequest->requestable_type === \App\Models\MentorshipPlan::class;

        DB::beginTransaction();
        try {
            if ($isMentorshipPlanBooking) {
                if ($mentorshipRequest->trainee_id !== Auth::user()->User_ID) {
                    return response()->json(['message' => 'طلب المنتورشب مش بتاعك'], 403);
                }

                if ($mentorshipRequest->status !== 'accepted') {
                    return response()->json(['message' => 'طلب المنتورشب لازم يكون مقبول قبل الحجز'], 400);
                }

                if ($mentorshipRequest->requestable->service_id !== $service->service_id) {
                    return response()->json(['message' => 'الخدمة غير متطابقة مع طلب المنتورشب'], 400);
                }

                $sessionCount = 4;
                $bookedSessionsCount = NewSession::where('mentorship_request_id', $mentorshipRequestId)->count();
                $remainingSessions = $sessionCount - $bookedSessionsCount;

                if ($remainingSessions <= 0) {
                    return response()->json(['message' => 'لقد حجزت بالفعل الحد الأقصى لعدد جلسات خطة المنتورشيب'], 400);
                }

                if ($remainingSessions < $sessionCount) {
                    return response()->json(['message' => 'خطة المنتورشيب تتطلب حجز 4 جلسات دفعة واحد'], 400);
                }

                $sessionsToBook = [];
                for ($i = 0; $i < $sessionCount; $i++) {
                    $sessionDate = $startDate->copy()->addWeeks($i);
                    $sessionDateTime = $sessionDate->copy()->setTime($startTime->hour, $startTime->minute, $startTime->second);
                    $slotEnd = $sessionDateTime->copy()->addMinutes($durationMinutes);

                    Log::info('Preparing Mentorship Plan session', [
                        'mentorship_request_id' => $mentorshipRequestId,
                        'session_index' => $i,
                        'date_time' => $sessionDateTime->toDateTimeString(),
                        'timezone' => $sessionDateTime->getTimezone()->getName(),
                    ]);

                    $availability = CoachAvailability::where('coach_id', (int)$coachId)
                        ->where('Day_Of_Week', $sessionDate->format('l'))
                        ->where('Start_Time', '<=', $sessionDateTime->format('H:i:s'))
                        ->where('End_Time', '>=', $slotEnd->format('H:i:s'))
                        ->first();

                    if (!$availability) {
                        return response()->json(['message' => "السلوت المختار غير متاح في {$sessionDateTime->toDateString()}"], 400);
                    }

                    $conflictingSessions = NewSession::where('coach_id', (int)$coachId)
                        ->whereIn('status', ['Pending', 'Scheduled'])
                        ->whereDate('date_time', $sessionDateTime->toDateString())
                        ->whereDoesntHave('mentorshipRequest', function ($query) {
                            $query->where('requestable_type', GroupMentorship::class);
                        })
                        ->get()
                        ->filter(function($existingSession) use ($sessionDateTime, $slotEnd) {
                            $reqStart = Carbon::parse($existingSession->date_time);
                            $reqEnd = $reqStart->copy()->addMinutes($existingSession->duration);
                            return $sessionDateTime->equalTo($reqStart) && $slotEnd->equalTo($reqEnd);
                        });

                    if ($conflictingSessions->any()) {
                        return response()->json(['message' => "السلوت المختار محجوز بالفعل في {$sessionDateTime->toDateString()}"], 400);
                    }

                    $sessionsToBook[] = [
                        'date_time' => $sessionDateTime->toDateTimeString(),
                        'duration' => $durationMinutes,
                    ];
                }

                $createdSessions = [];
                foreach ($sessionsToBook as $sessionData) {
                    $session = NewSession::create([
                        'user_id' => Auth::user()->User_ID,
                        'coach_id' => $coachId,
                        'date_time' => $sessionData['date_time'],
                        'duration' => $sessionData['duration'],
                        'status' => 'Pending',
                        'service_id' => $service->service_id,
                        'mentorship_request_id' => $mentorshipRequestId,
                    ]);
                    $createdSessions[] = $session;

                    Log::info('Created Mentorship Plan session', [
                        'new_session_id' => $session->new_session_id,
                        'date_time' => $session->date_time,
                        'mentorship_request_id' => $mentorshipRequestId,
                    ]);
                }

                \App\Models\PendingPayment::create([
                    'mentorship_request_id' => $mentorshipRequestId,
                    'payment_due_at' => now()->addHours(24),
                ]);

                DB::commit();
                Log::info('تم حجز كل جلسات خطة المنتورشيب، في انتظار الدفع', [
                    'mentorship_request_id' => $mentorshipRequestId,
                    'sessions' => $createdSessions,
                ]);

                return response()->json([
                    'message' => 'تم حجز كل الجلسات بنجاح. تابع الدفع باستخدام /api/payment/initiate/mentorship/' . $mentorshipRequestId,
                    'sessions' => $createdSessions,
                ]);
            } else {
                $tempSessionId = Uuid::uuid4()->toString();

                $sessionData = [
                    'temp_session_id' => $tempSessionId,
                    'user_id' => Auth::user()->User_ID,
                    'coach_id' => $coachId,
                    'service_id' => $service->service_id,
                    'date_time' => $sessionDateTime->toDateTimeString(),
                    'duration' => $durationMinutes,
                ];

                DB::commit();
                Log::info('تم بدء حجز خدمة غير منتورة، في انتظار الدفع', [
                    'temp_session_id' => $tempSessionId,
                    'service_id' => $service->service_id,
                    'user_id' => Auth::user()->User_ID,
                ]);

                return response()->json([
                    'message' => 'تم بدء الحجز بنجاح. تابع الدفع.',
                    'payment_url' => "/api/payment/initiate/session/$tempSessionId",
                    'session_data' => $sessionData,
                ]);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('فشل بدء الحجز', [
                'coach_id' => $coachId,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'حدث خطأ أثناء الحجز: ' . mb_convert_encoding($e->getMessage(), 'UTF-8', 'UTF-8')], 500);
        }
    }
}
