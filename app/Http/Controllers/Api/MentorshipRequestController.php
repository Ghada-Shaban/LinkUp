<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\MentorshipRequestAccepted;
use App\Models\MentorshipRequest;
use App\Models\NewSession;
use App\Models\Service;
use App\Models\MentorshipPlan;
use App\Models\GroupMentorship;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class MentorshipRequestController extends Controller
{
    public function requestMentorship(Request $request)
    {
        $request->validate([
            'service_id' => 'required|exists:services,service_id',
            'service_type' => 'required|in:MentorshipPlan,GroupMentorship',
        ]);

        $typeMap = [
            'MentorshipPlan' => \App\Models\MentorshipPlan::class,
            'GroupMentorship' => \App\Models\GroupMentorship::class,
        ];

        $serviceTypeInput = $request->input('service_type');
        $modelClass = $typeMap[$serviceTypeInput];
        $serviceId = $request->input('service_id');

        // Get the actual service (plan or group mentorship)
        $service = $modelClass::findOrFail($serviceId);

        // Pull the coach from the service relationship
        $coachId = $service->service->coach_id;

        $mentorshipRequest = MentorshipRequest::create([
            'requestable_id' => $serviceId,
            'requestable_type' => $modelClass,
            'trainee_id' => Auth::user()->User_ID,
            'coach_id' => $coachId,
            'status' => 'pending',
        ]);

        // Load trainee data for the response
        $trainee = User::findOrFail(Auth::user()->User_ID);

        return response()->json([
            'message' => 'Mentorship request sent successfully.',
            'request' => $mentorshipRequest,
            'trainee' => [
                'name' => $trainee->full_name,
                'email' => $trainee->email,
                'profile_picture' => $trainee->profile_picture ?? null,
            ],
            'service' => [
                'service_id' => $service->service_id,
                'title' => $service->title,
                'session_count' => $service instanceof MentorshipPlan ? $service->session_count : null,
            ]
        ]);
    }

    public function traineegetrequest(Request $request)
    {
        $user = auth()->user();

        if ($user->role_profile !== 'Trainee') {
            return response()->json(['message' => 'Only trainees can view their requests.'], 403);
        }

        $requests = MentorshipRequest::with('coach', 'requestable')
            ->where('trainee_id', $user->User_ID)
            ->latest()
            ->get();

        return response()->json($requests);
    }

    public function viewRequests()
    {
        $user = auth()->user();

        if ($user->role_profile !== 'Coach') {
            return response()->json(['message' => 'Only coaches can view their requests.'], 403);
        }

        $requests = MentorshipRequest::with('trainee', 'requestable')
            ->where('coach_id', $user->User_ID)
            ->latest()
            ->get();

        return response()->json($requests);
    }

    public function acceptRequest($id)
    {
        $user = auth()->user();
        if ($user->role_profile !== 'Coach') {
            return response()->json(['message' => 'Only coaches can accept requests.'], 403);
        }

        $request = MentorshipRequest::find($id);

        if (!$request) {
            return response()->json(['message' => 'Request not found.'], 404);
        }

        if ($request->coach_id !== $user->User_ID) {
            return response()->json(['message' => 'This request does not belong to you.'], 403);
        }

        $request->status = 'accepted';
        $request->responded_at = now();
        $request->save();

        // إرسال الإيميل للـ Trainee
        try {
            Mail::to($request->trainee->email)->send(RequestAccepted($request));
        } catch (\Exception $e) {
            \Log::error('Failed to send Mentorship Request Accepted email', [
                'request_id' => $id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Request accepted successfully. Trainee can now proceed to payment using /payment/initiate/mentorship_request/' . $id,
        ]);
    }

    public function rejectRequest($id)
    {
        $user = auth()->user();

        if ($user->role_profile !== 'Coach') {
            return response()->json(['message' => 'Only coaches can reject requests.'], 403);
        }

        $request = MentorshipRequest::find($id);

        if (!$request) {
            return response()->json(['message' => 'Request not found.'], 404);
        }

        if ($request->coach_id !== $user->User_ID) {
            return response()->json(['message' => 'This request does not belong to you.'], 403);
        }

        $request->status = 'rejected';
        $request->save();

        return response()->json(['message' => 'Request rejected successfully.']);
    }

    public function scheduleSessions(Request $request, $id)
    {
        $mentorshipRequest = MentorshipRequest::findOrFail($id);

        if ($mentorshipRequest->trainee_id !== Auth::user()->User_ID) {
            return response()->json(['message' => 'This request does not belong to you.'], 403);
        }

        if ($mentorshipRequest->status !== 'accepted') {
            return response()->json(['message' => 'Request must be accepted to schedule sessions.'], 400);
        }

        if ($mentorshipRequest->requestable_type !== \App\Models\MentorshipPlan::class) {
            return response()->json(['message' => 'Scheduling is only for Mentorship Plans.'], 400);
        }

        $mentorshipPlan = $mentorshipRequest->requestable;
        $sessionCount = $mentorshipPlan->session_count;

        $request->validate([
            'sessions' => 'required|array|size:' . $sessionCount,
            'sessions.*.session_time' => 'required|date|after:now',
            'sessions.*.duration_minutes' => 'required|integer|min:30',
        ]);

        $coachId = $mentorshipRequest->coach_id;
        $sessions = $request->input('sessions');
        $planSchedule = [];

        foreach ($sessions as $index => $session) {
            $slotStart = Carbon::parse($session['session_time']);
            $planSchedule[] = [
                'session_time' => $slotStart->toDateTimeString(),
                'duration_minutes' => $session['duration_minutes'],
            ];
        }

        DB::beginTransaction();
        try {
            foreach ($planSchedule as $session) {
                NewSession::create([
                    'mentorship_request_id' => $mentorshipRequest->id,
                    'trainee_id' => $mentorshipRequest->trainee_id,
                    'coach_id' => $mentorshipRequest->coach_id,
                    'session_time' => $session['session_time'],
                    'duration_minutes' => $session['duration_minutes'],
                    'status' => 'upcoming',
                ]);
            }

            DB::commit();
            Log::info('Sessions scheduled successfully', [
                'mentorship_request_id' => $mentorshipRequest->id,
            ]);

            return response()->json([
                'message' => 'Sessions scheduled successfully.',
                'request' => $mentorshipRequest->fresh(['requestable', 'trainee.user'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to schedule sessions', [
                'request_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
