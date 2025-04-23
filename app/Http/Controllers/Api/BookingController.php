<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Availability;
use App\Models\Service;
use App\Models\MentorshipRequest;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class BookingController extends Controller
{
    /**
     * عرض الأيام المتاحة للكوتش
     */
    public function getAvailableDates(Request $request, $coachId)
    {
        $validated = $request->validate([
            'service_id' => 'required|exists:services,service_id',
            'month' => 'required|date_format:Y-m',
        ]);

        $service = Service::findOrFail($request->service_id);
        if ($service->coach_id !== $coachId) {
            Log::warning('Service does not belong to coach', [
                'coach_id' => $coachId,
                'service_id' => $request->service_id,
            ]);
            return response()->json(['message' => 'Invalid service'], 400);
        }

        $startOfMonth = Carbon::parse($request->month)->startOfMonth();
        $endOfMonth = Carbon::parse($request->month)->endOfMonth();

        // جلب الأيام المتاحة من coach_available_times
        $availabilities = Availability::where('coach_id', $coachId)
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->where('is_booked', false)
            ->get()
            ->groupBy('date')
            ->map(function ($group) {
                return [
                    'date' => $group->first()->date,
                    'is_available' => true,
                ];
            })
            ->values();

        // إضافة كل الأيام مع الحالة
        $allDays = [];
        $currentDate = $startOfMonth->copy();
        while ($currentDate <= $endOfMonth) {
            $isAvailable = $availabilities->firstWhere('date', $currentDate->toDateString());
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
        $validated = $request->validate([
            'service_id' => 'required|exists:services,service_id',
            'date' => 'required|date|after:now',
            'duration' => 'required|integer|in:30,60,120',
        ]);

        $service = Service::findOrFail($request->service_id);
        if ($service->coach_id !== $coachId) {
            Log::warning('Service does not belong to coach', [
                'coach_id' => $coachId,
                'service_id' => $request->service_id,
            ]);
            return response()->json(['message' => 'Invalid service'], 400);
        }

        // جلب الفترات المتاحة من coach_available_times
        $availabilities = Availability::where('coach_id', $coachId)
            ->where('date', $request->date)
            ->where('is_booked', false)
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
        foreach ($availabilities as $availability) {
            $start = Carbon::parse($availability->date . ' ' . $availability->start_time);
            $end = Carbon::parse($availability->date . ' ' . $availability->end_time);
            $duration = $request->duration;

            // تقسيم الفترة المتاحة إلى Slots
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
            'duration' => $request->duration,
            'slots_count' => count($slots),
        ]);

        return response()->json([
            'available_slots' => array_values($slots),
        ]);
    }
}
