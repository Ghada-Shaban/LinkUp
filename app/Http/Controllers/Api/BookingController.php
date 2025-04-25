<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CoachAvailability;
use App\Models\Service;
use App\Models\MentorshipRequest;
use App\Models\GroupMentorship;
use App\Models\NewSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class BookingController extends Controller
{
    /**
     * عرض الأيام المتاحة للكوتش
     */
    public function getAvailableDates(Request $request, $coachId)
    {
        // التحقق من أن الـ Trainee ماعندوش طلبات Pending أو Accepted أو Pending Payment
        $existingRequest = MentorshipRequest::where('trainee_id', Auth::id())
            ->whereIn('status', ['pending', 'accepted', 'pending_payment'])
            ->exists();

        if ($existingRequest) {
            Log::warning('Trainee has an existing request', [
                'trainee_id' => Auth::id(),
                'coach_id' => $coachId,
            ]);
            return response()->json(['message' => 'You already have a pending, accepted, or awaiting payment request'], 403);
        }

        $validated = $request->validate([
            'service_id' => 'required|exists:services,service_id',
            'month' => 'required|date_format:Y-m',
        ]);

        $service = Service::findOrFail($request->service_id);

        // Debug Log لمعرفة قيم وأنواع coach_id
        Log::debug('Service details', [
            'service_id' => $request->service_id,
            'coach_id_from_service' => $service->coach_id,
            'coach_id_from_request' => $coachId,
            'service_coach_id_type' => gettype($service->coach_id),
            'coach_id_type' => gettype($coachId),
            'service_coach_id_value' => var_export($service->coach_id, true),
        ]);

        // استخدام Casting صريح لتجنب مشاكل Type Casting
        if ((int)$service->coach_id !== (int)$coachId) {
            Log::warning('Service does not belong to coach', [
                'coach_id' => $coachId,
                'service_id' => $request->service_id,
                'service_coach_id' => $service->coach_id,
                'service_coach_id_type' => gettype($service->coach_id),
                'coach_id_type' => gettype($coachId),
            ]);
            return response()->json([
                'message' => 'Invalid service',
                'details' => [
                    'service_id' => $request->service_id,
                    'coach_id_from_request' => $coachId,
                    'coach_id_from_service' => $service->coach_id,
                    'service_coach_id_type' => gettype($service->coach_id),
                    'coach_id_type' => gettype($coachId),
                ]
            ], 400);
        }

        $startOfMonth = Carbon::parse($request->month)->startOfMonth();
        $endOfMonth = Carbon::parse($request->month)->endOfMonth();

        // جلب الأيام المتاحة من coach_available_times بناءً على Day_Of_Week
        $availabilities = CoachAvailability::where('User_ID', $coachId)
            ->whereIn('Day_Of_Week', $this->getDaysOfWeekInMonth($startOfMonth, $endOfMonth))
            ->get()
            ->groupBy('Day_Of_Week');

        // جلب الحجوزات (pending أو upcoming) في الشهر من جدول new_sessions
        $bookedSlots = NewSession::where('coach_id', $coachId)
            ->whereIn('status', ['pending', 'upcoming'])
            ->whereBetween('session_time', [$startOfMonth, $endOfMonth])
            ->get()
            ->groupBy(function ($session) {
                return Carbon::parse($session->session_time)->toDateString();
            })
            ->map(function ($sessions, $date) {
                return $sessions->map(function ($session) {
                    return [
                        'start' => Carbon::parse($session->session_time)->format('H:i:s'),
                        'end' => Carbon::parse($session->session_time)
                            ->addMinutes($session->duration_minutes)
                            ->format('H:i:s'),
                    ];
                });
            });

        // إضافة كل الأيام مع الحالة
        $allDays = [];
        $currentDate = $startOfMonth->copy();
        while ($currentDate <= $endOfMonth) {
            $dayOfWeek = $currentDate->format('l');
            $dateString = $currentDate->toDateString();

            $status = 'unavailable';
            if (isset($availabilities[$dayOfWeek])) {
                // جلب كل الـ Slots المتاحة في اليوم
                $daySlots = [];
                foreach ($availabilities[$dayOfWeek] as $availability) {
                    $start = Carbon::parse($dateString . ' ' . $availability->Start_Time);
                    $end = Carbon::parse($dateString . ' ' . $availability->End_Time);
                    $duration = 60; // نفس الـ duration المستخدم في getAvailableSlots

                    while ($start->copy()->addMinutes($duration)->lte($end)) {
                        $slotStart = $start->copy();
                        $slotEnd = $slotStart->copy()->addMinutes($duration);

                        $daySlots[] = [
                            'start' => $slotStart->format('H:i:s'),
                            'end' => $slotEnd->format('H:i:s'),
                        ];

                        $start->addMinutes($duration);
                    }
                }

                // التحقق من الحجوزات في اليوم
                $bookedDaySlots = $bookedSlots[$dateString] ?? collect([]);
                $allBooked = true;
                $hasAvailable = false;

                foreach ($daySlots as $slot) {
                    $isBooked = $bookedDaySlots->contains(function ($bookedSlot) use ($slot) {
                        return $slot['start'] >= $bookedSlot['start'] && $slot['end'] <= $bookedSlot['end'];
                    });

                    if (!$isBooked) {
                        $hasAvailable = true;
                        $allBooked = false;
                        break;
                    }
                }

                if ($hasAvailable) {
                    $status = 'available';
                } elseif ($allBooked && $bookedDaySlots->isNotEmpty()) {
                    $status = 'booked';
                }
            }

            $allDays[] = [
                'date' => $dateString,
                'status' => $status,
            ];
            $currentDate->addDay();
        }

        Log::info('Fetched available dates', [
            'coach_id' => $coachId,
            'month' => $request->month,
            'dates_count' => count($availabilities),
            'booked_dates' => array_keys($bookedSlots->toArray()),
        ]);

        return response()->json([
            'available_dates' => $allDays,
            'service' => [
                'service_id' => $service->service_id,
                'name' => $service->name,
                'price' => $service->price,
            ],
        ]);
    }

    /**
     * عرض الفترات المتاحة في يوم معين
     */
    public function getAvailableSlots(Request $request, $coachId)
    {
        // التحقق من أن الـ Trainee ماعندوش طلبات Pending أو Accepted أو Pending Payment
        $existingRequest = MentorshipRequest::where('trainee_id', Auth::id())
            ->whereIn('status', ['pending', 'accepted', 'pending_payment'])
            ->exists();

        if ($existingRequest) {
            Log::warning('Trainee has an existing request', [
                'trainee_id' => Auth::id(),
                'coach_id' => $coachId,
            ]);
            return response()->json(['message' => 'You already have a pending, accepted, or awaiting payment request'], 403);
        }

        $validated = $request->validate([
            'service_id' => 'required|exists:services,service_id',
            'date' => 'required|date|after:now',
        ]);

        $service = Service::findOrFail($request->service_id);

        // Debug Log لمعرفة قيم وأنواع coach_id
        Log::debug('Service details for slots', [
            'service_id' => $request->service_id,
            'coach_id_from_service' => $service->coach_id,
            'coach_id_from_request' => $coachId,
            'service_coach_id_type' => gettype($service->coach_id),
            'coach_id_type' => gettype($coachId),
            'service_coach_id_value' => var_export($service->coach_id, true),
        ]);

        // استخدام Casting صريح لتجنب مشاكل Type Casting
        if ((int)$service->coach_id !== (int)$coachId) {
            Log::warning('Service does not belong to coach', [
                'coach_id' => $coachId,
                'service_id' => $request->service_id,
                'service_coach_id' => $service->coach_id,
                'service_coach_id_type' => gettype($service->coach_id),
                'coach_id_type' => gettype($coachId),
            ]);
            return response()->json([
                'message' => 'Invalid service',
                'details' => [
                    'service_id' => $request->service_id,
                    'coach_id_from_request' => $coachId,
                    'coach_id_from_service' => $service->coach_id,
                    'service_coach_id_type' => gettype($service->coach_id),
                    'coach_id_type' => gettype($coachId),
                ]
            ], 400);
        }

        // جلب الفترات المتاحة من coach_available_times بناءً على يوم الأسبوع
        $dayOfWeek = Carbon::parse($request->date)->format('l');
        $availabilities = CoachAvailability::where('User_ID', $coachId)
            ->where('Day_Of_Week', $dayOfWeek)
            ->get();

        // جلب الجلسات المحجوزة (pending أو upcoming) في اليوم من جدول new_sessions
        $bookedSlots = NewSession::where('coach_id', $coachId)
            ->whereIn('status', ['pending', 'upcoming'])
            ->whereDate('session_time', $request->date)
            ->get()
            ->map(function ($session) {
                return [
                    'start' => Carbon::parse($session->session_time)->format('H:i:s'),
                    'end' => Carbon::parse($session->session_time)
                        ->addMinutes($session->duration_minutes)
                        ->format('H:i:s'),
                ];
            });

        // توليد كل الـ Time Slots في اليوم (من 00:00 إلى 23:00)
        $slots = [];
        $duration = 60; // كل Slot ساعة
        $startOfDay = Carbon::parse($request->date)->startOfDay();

        for ($hour = 0; $hour < 24; $hour++) {
            $slotStart = $startOfDay->copy()->addHours($hour);
            $slotEnd = $slotStart->copy()->addMinutes($duration);

            // التحقق إذا كان الـ Slot ده موجود في coach_available_times
            $isAvailableInSchedule = false;
            foreach ($availabilities as $availability) {
                $availStart = Carbon::parse($request->date . ' ' . $availability->Start_Time);
                $availEnd = Carbon::parse($request->date . ' ' . $availability->End_Time);

                if ($slotStart->gte($availStart) && $slotEnd->lte($availEnd)) {
                    $isAvailableInSchedule = true;
                    break;
                }
            }

            // التحقق إذا كان الـ Slot محجوز
            $isBooked = false;
            if ($isAvailableInSchedule) {
                $isBooked = $bookedSlots->contains(function ($booked) use ($slotStart, $slotEnd) {
                    return $slotStart->format('H:i:s') >= $booked['start'] &&
                           $slotEnd->format('H:i:s') <= $booked['end'];
                });
            }

            // تحديد الـ status
            $status = 'unavailable';
            if ($isAvailableInSchedule) {
                $status = $isBooked ? 'booked' : 'available';
            }

            $slots[] = [
                'time' => $slotStart->format('H:i'),
                'status' => $status,
            ];
        }

        Log::info('Fetched available slots', [
            'coach_id' => $coachId,
            'date' => $request->date,
            'duration' => $duration,
            'slots_count' => count($slots),
            'slots' => $slots,
        ]);

        return response()->json([
            'available_slots' => $slots,
        ]);
    }

    /**
     * دالة مساعدة لجلب أيام الأسبوع في الشهر
     */
    protected function getDaysOfWeekInMonth($startOfMonth, $endOfMonth)
    {
        $days = [];
        $currentDate = $startOfMonth->copy();
        while ($currentDate <= $endOfMonth) {
            $days[] = $currentDate->format('l');
            $currentDate->addDay();
        }
        return array_unique($days);
    }
}
