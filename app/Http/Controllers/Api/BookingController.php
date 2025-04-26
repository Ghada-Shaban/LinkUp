<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CoachAvailability;
use App\Models\NewSession;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class BookingController extends Controller
{
    public function getAvailableDates(Request $request, $coachId)
    {
        $availabilities = CoachAvailability::where('User_ID', $coachId)->get();
        $dates = $availabilities->map(function ($availability) {
            return [
                'day_of_week' => $availability->Day_Of_Week,
                'start_time' => $availability->Start_Time,
                'end_time' => $availability->End_Time,
            ];
        });

        return response()->json($dates);
    }

    public function getAvailableSlots(Request $request, $coachId)
    {
        $date = $request->query('date');
        $dayOfWeek = Carbon::parse($date)->format('l');

        $availabilities = CoachAvailability::where('User_ID', $coachId)
            ->where('Day_Of_Week', $dayOfWeek)
            ->get();

        $slots = $availabilities->map(function ($availability) {
            return [
                'start_time' => $availability->Start_Time,
                'end_time' => $availability->End_Time,
            ];
        });

        return response()->json($slots);
    }

    public function bookService(Request $request, $coachId)
    {
        $request->validate([
            'service_id' => 'required|exists:services,service_id',
            'session_time' => 'required|date|after:now',
            'duration_minutes' => 'required|integer|min:30',
        ]);

        $service = Service::findOrFail($request->service_id);
        if ($service->coach_id !== (int)$coachId) {
            return response()->json(['message' => 'Service does not belong to this coach'], 403);
        }

        $slotStart = Carbon::parse($request->session_time);
        $slotEnd = $slotStart->copy()->addMinutes($request->duration_minutes);
        $dayOfWeek = $slotStart->format('l');

        // Check Coach Availability
        $availability = CoachAvailability::where('User_ID', (int)$coachId)
            ->where('Day_Of_Week', $dayOfWeek)
            ->where('Start_Time', '<=', $slotStart->format('H:i:s'))
            ->where('End_Time', '>=', $slotEnd->format('H:i:s'))
            ->first();

        if (!$availability) {
            Log::warning('Selected slot is not available', [
                'trainee_id' => Auth::id(),
                'coach_id' => $coachId,
                'date' => $slotStart->toDateString(),
                'day_of_week' => $dayOfWeek,
                'start_time' => $slotStart->format('H:i'),
                'duration' => $request->duration_minutes,
            ]);
            return response()->json(['message' => 'Selected slot is not available'], 400);
        }

        // Check for conflicts
        $conflictingSessions = NewSession::where('coach_id', (int)$coachId)
            ->whereIn('status', ['pending', 'upcoming'])
            ->whereDate('session_time', $slotStart->toDateString())
            ->get()
            ->filter(function ($existingSession) use ($slotStart, $slotEnd) {
                $reqStart = Carbon::parse($existingSession->session_time);
                $reqEnd = $reqStart->copy()->addMinutes($existingSession->duration_minutes);
                return $slotStart < $reqEnd && $slotEnd > $reqStart;
            });

        if ($conflictingSessions->isNotEmpty()) {
            Log::warning('Slot conflicts with existing sessions', [
                'trainee_id' => Auth::id(),
                'coach_id' => $coachId,
                'date' => $slotStart->toDateString(),
                'day_of_week' => $dayOfWeek,
                'start_time' => $slotStart->format('H:i'),
            ]);
            return response()->json(['message' => 'Selected slot is already reserved'], 400);
        }

        DB::beginTransaction();
        try {
            $session = NewSession::create([
                'trainee_id' => Auth::user()->User_ID,
                'coach_id' => $coachId,
                'session_time' => $slotStart->toDateTimeString(),
                'duration_minutes' => $request->duration_minutes,
                'status' => 'pending', // Pending until payment is completed
                'service_id' => $service->service_id,
            ]);

            DB::commit();
            Log::info('Session booked, awaiting payment', [
                'session_id' => $session->id,
            ]);

            return response()->json([
                'message' => 'Session booked successfully. Please proceed to payment using /payment/initiate/service/' . $session->id,
                'session' => $session
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to book session', [
                'coach_id' => $coachId,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
