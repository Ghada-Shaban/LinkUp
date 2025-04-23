<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CoachAvailability; // تغيير من Availability إلى CoachAvailability
use App\Models\Service;
use App\Models\MentorshipRequest;
use App\Models\User;
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
        // التحقق من أن الـ Trainee ماعندوش طلبات Pending أو Accepted
        $existingRequest = MentorshipRequest::where('trainee_id', Auth::id())
            ->whereIn('status', ['pending', 'accepted'])
            ->exists();

        if ($existingRequest) {
            Log::warning('Trainee has an existing request', [
                'trainee_id' => Auth::id(),
                'coach_id' => $coachId,
            ]);
            return response()->json(['message' => 'You already have a pending or accepted request'], 403);
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
            ->groupBy('Day_Of_Week')
            ->map(function ($group) {
                return [
                    'day_of_week' => $group->first()->Day_Of_Week,
                    'is_available' => true,
                ];
            })
            ->values();

        // إضافة كل الأيام مع الحالة
        $allDays = [];
        $currentDate = $startOfMonth->copy();
        while ($currentDate <= $endOfMonth) {
            $dayOfWeek = $currentDate->format('l'); // اسم اليوم (Monday, Tuesday, ...)
            $isAvailable = $availabilities->firstWhere('day_of_week', $dayOfWeek);
            $allDays[] = [
                'date' => $currentDate->toDateString(),
                'status' => $isAvailable ? 'available' : 'unavailable',
            ];
            $currentDate->addDay();
        }

        Log::info('Fetched available dates', [
            'coach_id' => $coachId,
            'month' => $request->month,
            'dates_count' => count($availabilities),
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
        // التحقق من أن الـ Trainee ماعندوش طلبات Pending أو Accepted
        $existingRequest = MentorshipRequest::where('trainee_id', Auth::id())
            ->whereIn('status', ['pending', 'accepted'])
            ->exists();

        if ($existingRequest) {
            Log::warning('Trainee has an existing request', [
                'trainee_id' => Auth::id(),
                'coach_id' => $coachId,
            ]);
            return response()->json(['message' => 'You already have a pending or accepted request'], 403);
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

        // جلب الطلبات الموجودة (Pending أو Accepted) في اليوم
        $bookedSlots = MentorshipRequest::where('coach_id', $coachId)
            ->whereIn('status', ['pending', 'accepted'])
            ->whereDate('first_session_time', $request->date)
            ->get()
            ->map(function ($request) {
                return [
                    'start' => Carbon::parse($request->first_session_time),
                    'end' => Carbon::parse($request->first_session_time)->addMinutes($request->duration_minutes),
                ];
            });

        $slots = [];
        $duration = 60; // تثبيت الـ duration على 60 دقيقة

        foreach ($availabilities as $availability) {
            $start = Carbon::parse($request->date . ' ' . $availability->Start_Time);
            $end = Carbon::parse($request->date . ' ' . $availability->End_Time);

            // تقسيم الفترة المتاحة إلى Slots بساعة
            while ($start->copy()->addMinutes($duration)->lte($end)) {
                $slotStart = $start->copy();
                $slotEnd = $slotStart->copy()->addMinutes($duration);

                // التحقق من عدم التداخل مع الطلبات الموجودة
                $isAvailable = true;
                foreach ($bookedSlots as $booked) {
                    if ($slotStart < $booked['end'] && $slotEnd > $booked['start']) {
                        $isAvailable = false;
                        break;
                    }
                }

                if ($isAvailable) {
                    $slots[] = $slotStart->format('H:i');
                }

                $start->addMinutes($duration);
            }
        }

        // إزالة التكرارات (لو فيه تداخل في الفترات)
        $slots = array_unique($slots);

        Log::info('Fetched available slots', [
            'coach_id' => $coachId,
            'date' => $request->date,
            'duration' => $duration,
            'slots_count' => count($slots),
        ]);

        return response()->json([
            'available_slots' => array_values($slots),
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
            $days[] = $currentDate->format('l'); // Monday, Tuesday, ...
            $currentDate->addDay();
        }
        return array_unique($days);
    }
}
