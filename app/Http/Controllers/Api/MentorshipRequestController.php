<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\NewMentorshipRequest;
use App\Mail\GroupMentorshipRequestAccepted;
use App\Mail\MentorshipPlanRequestAccepted;
use App\Mail\RequestRejected;
use App\Models\MentorshipRequest;
use App\Models\NewSession;
use App\Models\Service;
use App\Models\MentorshipPlan;
use App\Models\GroupMentorship;
use App\Models\User;
use App\Models\PendingPayment;
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
        $service = $modelClass::findOrFail($serviceId);
        $coachId = $service->service->coach_id;

        if ($serviceTypeInput === 'GroupMentorship') {
            // التحقق من عدد المشاركين الحاليين
            $currentParticipants = MentorshipRequest::where('requestable_type', $modelClass)
                ->where('requestable_id', $serviceId)
                ->where('status', 'accepted')
                ->count();

            $maxParticipants = 5; // الحد الأقصى للمشاركين
            if ($currentParticipants >= $maxParticipants) {
                return response()->json([
                    'message' => "The group is full. The maximum number of participants ($maxParticipants) has been reached."
                ], 400);
            }

            $existingRequest = MentorshipRequest::where('trainee_id', Auth::user()->User_ID)
                ->where('requestable_type', $modelClass)
                ->where('requestable_id', $serviceId)
                ->exists();

            if ($existingRequest) {
                return response()->json(['message' => 'You have already requested this Group Mentorship'], 400);
            }
        }

        $mentorshipRequest = MentorshipRequest::create([
            'requestable_id' => $serviceId,
            'requestable_type' => $modelClass,
            'trainee_id' => Auth::user()->User_ID,
            'coach_id' => $coachId,
            'status' => 'pending',
        ]);

        $trainee = User::findOrFail(Auth::user()->User_ID);

        try {
            $coach = User::findOrFail($coachId);
            Mail::to($coach->email)->send(new NewMentorshipRequest($mentorshipRequest));
        } catch (\Exception $e) {
        }

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
            ->whereNotIn('id', function ($query) {
                $query->select('mentorship_request_id')
                      ->from('payments')
                      ->whereNotNull('mentorship_request_id');
            })
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

        $request = MentorshipRequest::with('trainee')->find($id);

        if (!$request) {
            return response()->json(['message' => 'Request not found.'], 404);
        }

        if ($request->coach_id !== $user->User_ID) {
            return response()->json(['message' => 'This request does not belong to you.'], 403);
        }

        $request->status = 'accepted';
        $request->responded_at = now();
        $request->save();

        PendingPayment::create([
            'mentorship_request_id' => $request->id,
            'trainee_id' => $request->trainee_id,
            'coach_id' => $request->coach_id,
            'payment_due_at' => now()->addHours(24),
        ]);

        try {
            if ($request->requestable_type === \App\Models\MentorshipPlan::class) {
                Mail::to($request->trainee->email)->send(new MentorshipPlanRequestAccepted($request));
            } else {
                Mail::to($request->trainee->email)->send(new GroupMentorshipRequestAccepted($request));
            }
        } catch (\Exception $e) {
        }

        $nextStepMessage = $request->requestable_type === \App\Models\MentorshipPlan::class
            ? "Trainee can now book sessions using /api/coach/{$request->coach_id}/book"
            : "Trainee can now proceed to payment using /payment/initiate/mentorship_request/{$id}";

        return response()->json([
            'message' => "Request accepted successfully. {$nextStepMessage}",
        ]);
    }

    public function rejectRequest($id)
    {
        $user = auth()->user();

        if ($user->role_profile !== 'Coach') {
            return response()->json(['message' => 'Only coaches can reject requests.'], 403);
        }

        $request = MentorshipRequest::with(['trainee', 'requestable'])->find($id);

        if (!$request) {
            return response()->json(['message' => 'Request not found.'], 404);
        }

        if ($request->coach_id !== $user->User_ID) {
            return response()->json(['message' => 'This request does not belong to you.'], 403);
        }

        $request->status = 'rejected';
        $request->save();

        try {
            if ($request->trainee && $request->trainee->email) {
                Mail::to($request->trainee->email)->send(new RequestRejected($request));
            }
        } catch (\Exception $e) {
        }
        return response()->json(['message' => 'Request rejected successfully.']);
    }
}
