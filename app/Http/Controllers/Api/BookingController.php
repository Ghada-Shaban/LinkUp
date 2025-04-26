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
        $availabilities = CoachAvailability::where('coach_id', $coachId)->get();
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

        $availabilities = CoachAvailability::where('coach_id', $coachId)
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
            'mentorship_request_id' => 'nullable|exists:mentorship_requests,id', // Optional for MentorshipPlan sessions
        ]);

        $service = Service::findOrFail($request->service_id);
        if ($service->coach_id !== (int)$coachId) {
            return response()->json(['message' => 'Service does not belong to this coach'], 403);
        }

        $slotStart = Carbon::parse($request->session_time);
        $slotEnd = $slotStart->copy()->addMinutes($request->duration_minutes);
        $dayOfWeek = $slotStart->format('l');

        // Check Coach Availability
        $availability = CoachAvailability::where('coach_id', (int)$coachId)
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

        $mentorshipRequestId = $request->mentorship_request_id;
        $mentorshipRequest = null;

        // If mentorship_request_id is provided, validate it
        if ($mentorshipRequestId) {
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
            $bookedSessions = NewSession::where('mentorship_request_id', $mentorshipRequestId)->count();
            if ($bookedSessions >= $sessionCount) {
                return response()->json(['message' => 'You have already booked the maximum number of sessions for this Mentorship Plan.'], 400);
            }
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
                'mentorship_request_id' => $mentorshipRequestId, // Will be null for regular services
            ]);

            // If this session is part of a MentorshipPlan, create a pending payment if all sessions are booked
            if ($mentorshipRequest) {
                $bookedSessions = NewSession::where('mentorship_request_id', $mentorshipRequestId)->count();
                if ($bookedSessions == $mentorshipRequest->requestable->session_count) {
                    \App\Models\PendingPayment::create([
                        'mentorship_request_id' => $mentorshipRequestId,
                        'payment_due_at' => now()->addHours(24),
                    ]);
                }

                $message = $bookedSessions < $mentorshipRequest->requestable->session_count
                    ? 'Session booked successfully. Book the remaining sessions to proceed to payment.'
                    : 'Session booked successfully. Proceed to payment using /payment/initiate/mentorship_request/' . $mentorshipRequestId;

                DB::commit();
                Log::info('Session booked for Mentorship Plan, awaiting payment', [
                    'session_id' => $session->id,
                    'mentorship_request_id' => $mentorshipRequestId,
                ]);

                return response()->json([
                    'message' => $message,
                    'session' => $session,
                ]);
            }

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
