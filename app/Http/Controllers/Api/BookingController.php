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
        // التحقق من المدخلات
        $request->validate([
            'service_id' => 'required|exists:services,service_id',
            'month' => 'required|date_format:Y-m',
        ]);

        $serviceId = $request->query('service_id');
        $month = $request->query('month');

        // التحقق إن الخدمة تابعة للكوتش
        $service = Service::where('service_id', $serviceId)
            ->where('coach_id', $coachId)
            ->first();

        if (!$service) {
            return response()->json(['message' => 'الخدمة دي مش تابعة للكوتش ده'], 403);
        }

        // تحديد نطاق الشهر
        $startOfMonth = Carbon::parse($month)->startOfMonth();
        $endOfMonth = Carbon::parse($month)->endOfMonth();
        $currentDate = Carbon::today();

        // جلب مواعيد أفايلبيليتي الكوتش
        $availabilities = CoachAvailability::where('coach_id', $coachId)->get();

        // جلب الجلسات المحجوزة في الشهر (باستثناء جلسات Group Mentorship)
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

        // مدة الجلسة 60 دقيقة
        $durationMinutes = 60;

        // إنشاء قائمة بالتواريخ للشهر
        $dates = [];
        for ($date = $startOfMonth->copy(); $date->lte($endOfMonth); $date->addDay()) {
            $dayOfWeek = $date->format('l');
            $dateString = $date->toDateString();

            // التحقق لو التاريخ في الماضي
            if ($date->lt($currentDate)) {
                $dates[] = [
                    'date' => $dateString,
                    'day_of_week' => $dayOfWeek,
                    'status' => 'unavailable',
                ];
                continue;
            }

            // التحقق لو الكوتش متاح في اليوم ده
            $dayAvailabilities = $availabilities->where('Day_Of_Week', $dayOfWeek);
            if ($dayAvailabilities->isEmpty()) {
                $dates[] = [
                    'date' => $dateString,
                    'day_of_week' => $dayOfWeek,
                    'status' => 'unavailable',
                ];
                continue;
            }

            // التحقق من وجود تايم سلوتس متاحة في كل رينجات الأفايلبيليتي
            $hasAvailableSlots = false;
            foreach ($dayAvailabilities as $availability) {
                $startTime = Carbon::parse($availability->Start_Time);
                $endTime = Carbon::parse($availability->End_Time);
                $currentTime = Carbon::parse($dateString)->setTime($startTime->hour, $startTime->minute);
                $endOfAvailability = Carbon::parse($dateString)->setTime($endTime->hour, $endTime->minute);

                while ($currentTime->lt($endOfAvailability)) {
                    $slotEnd = $currentTime->copy()->addMinutes($durationMinutes);
                    if ($slotEnd->gt($endOfAvailability)) {
                        break; // تجنب السلوتس الجزئية
                    }

                    // التحقق لو السلوت محجوز
                    $isSlotBooked = isset($bookedSessions[$dateString]) && $bookedSessions[$dateString]->filter(function ($session) use ($currentTime, $slotEnd) {
                        $sessionStart = Carbon::parse($session->date_time);
                        $sessionEnd = $sessionStart->copy()->addMinutes($session->duration);
                        return $currentTime->lt($sessionEnd) && $slotEnd->gt($sessionStart);
                    })->isNotEmpty();

                    if (!$isSlotBooked) {
                        $hasAvailableSlots = true;
                        break; // لو لقينا سلوت متاح، نوقف اللوب
                    }

                    $currentTime->addMinutes($durationMinutes);
                }

                if ($hasAvailableSlots) {
                    break; // لو لقينا سلوت متاح في رينج واحد، نوقف فحص باقي الرينجات
                }
            }

            $dates[] = [
                'date' => $dateString,
                'day_of_week' => $dayOfWeek,
                'status' => $hasAvailableSlots ? 'available' : 'booked',
            ];
        }

        return response()->json($dates);
    }

    public function getAvailableSlots(Request $request, $coachId)
    {
        // التحقق من المدخلات
        $request->validate([
            'date' => 'required|date',
            'service_id' => 'required|exists:services,service_id',
        ]);

        $date = $request->query('date');
        $serviceId = $request->query('service_id');
        $dayOfWeek = Carbon::parse($date)->format('l');

        // التحقق إن الخدمة تابعة للكوتش
        $service = Service::where('service_id', $serviceId)
            ->where('coach_id', $coachId)
            ->first();

        if (!$service) {
            return response()->json(['message' => 'الخدمة دي مش تابعة للكوتش ده'], 403);
        }

        // التحقق لو التاريخ في الماضي
        $selectedDate = Carbon::parse($date);
        if ($selectedDate->lt(Carbon::today())) {
            return response()->json(['message' => 'لا يمكن حجز مواعيد في تواريخ سابقة'], 400);
        }

        // جلب مواعيد أفايلبيليتي الكوتش لليوم
        $availabilities = CoachAvailability::where('coach_id', $coachId)
            ->where('Day_Of_Week', $dayOfWeek)
            ->get();

        // تسجيل الأفايلبيليتي للتأكد من الكود
        Log::info('Coach Availabilities for coach_id: ' . $coachId . ', day: ' . $dayOfWeek, [
            'availabilities' => $availabilities->toArray(),
        ]);

        if ($availabilities->isEmpty()) {
            return response()->json([]);
        }

        // جمع كل رينجات الأفايلبيليتي لليوم
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

        // جلب الجلسات المحجوزة للتاريخ (باستثناء جلسات Group Mentorship)
        $bookedSessions = NewSession::where('coach_id', $coachId)
            ->whereIn('status', ['Pending', 'Scheduled'])
            ->whereDate('date_time', $selectedDate->toDateString())
            ->whereDoesntHave('mentorshipRequest', function ($query) {
                $query->where('requestable_type', 'App\\Models\\GroupMentorship');
            })
            ->get();

        // تسجيل الجلسات المحجوزة للتأكد من الكود
        Log::info('Booked Sessions for coach_id: ' . $coachId . ', date: ' . $selectedDate->toDateString(), [
            'booked_sessions' => $bookedSessions->toArray(),
        ]);

        // إنشاء السلوتس بس جوا رينجات الأفايلبيليتي
        $slots = [];
        $durationMinutes = 60; // مدة الجلسة ثابتة 60 دقيقة

        foreach ($availabilityRanges as $range) {
            $currentTime = Carbon::parse($date)->setTime($range['start_hour'], $range['start_minute']);
            $endOfAvailability = Carbon::parse($date)->setTime($range['end_hour'], $range['end_minute']);

            while ($currentTime->lt($endOfAvailability)) {
                $slotEnd = $currentTime->copy()->addMinutes($durationMinutes);
                if ($slotEnd->gt($endOfAvailability)) {
                    break; // تجنب السلوتس الجزئية
                }

                // تنسيق التوقيت بصيغة 24 ساعة (H:i)
                $slotStartFormatted = $currentTime->format('H:i');
                $slotEndFormatted = $slotEnd->format('H:i');

                // التحقق لو السلوت محجوز
                $isBooked = $bookedSessions->filter(function ($session) use ($currentTime, $slotEnd) {
                    $sessionStart = Carbon::parse($session->date_time);
                    $sessionEnd = $sessionStart->copy()->addMinutes($session->duration);
                    return $currentTime->lt($sessionEnd) && $slotEnd->gt($sessionStart);
                })->isNotEmpty();

                $status = $isBooked ? 'booked' : 'available';

                $slots[] = [
                    'start_time' => $slotStartFormatted,
                    'end_time' => $slotEndFormatted,
                    'status' => $status,
                ];

                $currentTime->addMinutes($durationMinutes);
            }
        }

        return response()->json($slots);
    }

    public function bookService(Request $request, $coachId)
    {
        // التحقق من المدخلات
        $request->validate([
            'service_id' => 'required|exists:services,service_id',
            'start_time' => 'required|date_format:H:i:s',
            'start_date' => 'required|date|after:now',
            'mentorship_request_id' => 'nullable|exists:mentorship_requests,id', // اختياري للخدمات العادية
        ]);

        $service = Service::findOrFail($request->service_id);
        if ($service->coach_id !== (int)$coachId) {
            return response()->json(['message' => 'الخدمة دي مش تابعة للكوتش ده'], 403);
        }

        $mentorshipRequestId = $request->mentorship_request_id;
        $mentorshipRequest = $mentorshipRequestId ? MentorshipRequest::findOrFail($mentorshipRequestId) : null;

        $durationMinutes = 60; // مدة الجلسة ثابتة 60 دقيقة
        $startDate = Carbon::parse($request->start_date);
        $startTime = Carbon::parse($request->start_time);
        $dayOfWeek = $startDate->format('l');
        $sessionDateTime = $startDate->setTime($startTime->hour, $startTime->minute, $startTime->second);
        $slotEnd = $sessionDateTime->copy()->addMinutes($durationMinutes);

        // التحقق لو الكوتش متاح في الوقت ده
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

        // التحقق من التعارض مع جلسات موجودة (باستثناء جلسات Group Mentorship)
        $conflictingSessions = NewSession::where('coach_id', (int)$coachId)
            ->whereIn('status', ['Pending', 'Scheduled'])
            ->whereDate('date_time', $sessionDateTime->toDateString())
            ->whereDoesntHave('mentorshipRequest', function ($query) {
                $query->where('requestable_type', 'App\\Models\\GroupMentorship');
            })
            ->get()
            ->filter(function ($existingSession) use ($sessionDateTime, $slotEnd) {
                $reqStart = Carbon::parse($existingSession->date_time);
                $reqEnd = $reqStart->copy()->addMinutes($existingSession->duration);
                return $sessionDateTime < $reqEnd && $slotEnd > $reqStart;
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

        // تحديد نوع الحجز بناءً على الخدمة وطلب المنتورشيب
        $isMentorshipPlanBooking = $mentorshipRequest && $mentorshipRequest->requestable_type === \App\Models\MentorshipPlan::class;

        DB::beginTransaction();
        try {
            if ($isMentorshipPlanBooking) {
                // التعامل مع حجز خطة المنتورشيب (4 جلسات، في انتظار الدفع)
                // التحقق إن طلب المنتورشيب يخص المستخدم المصادق عليه
                if ($mentorshipRequest->trainee_id !== Auth::user()->User_ID) {
                    return response()->json(['message' => 'طلب المنتورشيب ده مش بتاعك'], 403);
                }

                // التحقق إن طلب المنتورشيب مقبول
                if ($mentorshipRequest->status !== 'accepted') {
                    return response()->json(['message' => 'طلب المنتورشيب لازم يكون مقبول عشان تحجز جلسات'], 400);
                }

                // التحقق إن الخدمة تتطابق مع طلب المنتورشيب
                if ($mentorshipRequest->requestable->service_id !== $service->service_id) {
                    return response()->json(['message' => 'الخدمة مش متطابقة مع طلب المنتورشيب'], 400);
                }

                // التحقق من عدد الجلسات اللي هتتحجز (ثابت 4 لخطة المنتورشيب)
                $sessionCount = 4;
                $bookedSessionsCount = NewSession::where('mentorship_request_id', $mentorshipRequestId)->count();
                $remainingSessions = $sessionCount - $bookedSessionsCount;

                if ($remainingSessions <= 0) {
                    return response()->json(['message' => 'لقد حجزت بالفعل الحد الأقصى لعدد الجلسات لخطة المنتورشيب دي'], 400);
                }

                if ($remainingSessions < $sessionCount) {
                    return response()->json(['message' => 'خطة المنتورشيب بتتطلب حجز 4 جلسات مرة واحدة'], 400);
                }

                $sessionsToBook = [];
                for ($i = 0; $i < $sessionCount; $i++) {
                    $sessionDate = $startDate->copy()->addWeeks($i);
                    $sessionDateTime = $sessionDate->setTime($startTime->hour, $startTime->minute, $startTime->second);
                    $slotEnd = $sessionDateTime->copy()->addMinutes($durationMinutes);

                    // التحقق من الأفايلبيليتي لكل جلسة
                    $availability = CoachAvailability::where('coach_id', (int)$coachId)
                        ->where('Day_Of_Week', $sessionDate->format('l'))
                        ->where('Start_Time', '<=', $sessionDateTime->format('H:i:s'))
                        ->where('End_Time', '>=', $slotEnd->format('H:i:s'))
                        ->first();

                    if (!$availability) {
                        return response()->json(['message' => "السلوت المختار مش متاح في {$sessionDateTime->toDateString()}"], 400);
                    }

                    // التحقق من التعارض
                    $conflictingSessions = NewSession::where('coach_id', (int)$coachId)
                        ->whereIn('status', ['Pending', 'Scheduled'])
                        ->whereDate('date_time', $sessionDateTime->toDateString())
                        ->whereDoesntHave('mentorshipRequest', function ($query) {
                            $query->where('requestable_type', 'App\\Models\\GroupMentorship');
                        })
                        ->get()
                        ->filter(function ($existingSession) use ($sessionDateTime, $slotEnd) {
                            $reqStart = Carbon::parse($existingSession->date_time);
                            $reqEnd = $reqStart->copy()->addMinutes($existingSession->duration);
                            return $sessionDateTime < $reqEnd && $slotEnd > $reqStart;
                        });

                    if ($conflictingSessions->isNotEmpty()) {
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

                // إنشاء دفعة معلقة لخطة المنتورشيب
                \App\Models\PendingPayment::create([
                    'mentorship_request_id' => $mentorshipRequestId,
                    'payment_due_at' => now()->addHours(24),
                ]);

                DB::commit();
                Log::info('تم حجز كل الجلسات لخطة المنتورشيب، في انتظار الدفع', [
                    'mentorship_request_id' => $mentorshipRequestId,
                    'sessions' => $createdSessions,
                ]);

                return response()->json([
                    'message' => 'تم حجز كل الجلسات بنجاح. تابع الدفع باستخدام /api/payment/initiate/mentorship_request/' . $mentorshipRequestId,
                    'sessions' => $createdSessions,
                ]);
            } else {
                // التعامل مع حجز الخدمة العادية (Mock Interview, LinkedIn Optimization, CV Review, Project Assessment)
                // إنشاء معرف جلسة مؤقت للدفع
                $tempSessionId = Uuid::uuid4()->toString();

                // تحضير بيانات الجلسة لتمريرها لنقطة نهاية الدفع
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
            }
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
