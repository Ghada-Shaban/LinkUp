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

        // Send email to the Coach
        try {
            $coach = User::findOrFail($coachId);
            Mail::to($coach->email)->send(new NewMentorshipRequest($mentorshipRequest));
            Log::info('New mentorship request email sent to coach', [
                'request_id' => $mentorshipRequest->id,
                'coach_id' => $coachId,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send new mentorship request email', [
                'error' => $e->getMessage(),
                'request_id' => $mentorshipRequest->id,
                'coach_id' => $coachId,
            ]);
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

        if ($user
