<?php

namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\MentorshipRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use App\Models\Service;
use App\Models\MentorshipPlan;

class MentorshipRequestController extends Controller
{  
    public function store(Request $request)
    {
        $validated = $request->validate([
            'service_id' => [
                'required',
                'exists:services,service_id',
                function ($attribute, $value, $fail) use ($request) {
                    $service = Service::find($value);
                    
                    if (!$service) {
                        $fail('The selected service does not exist');
                        return;
                    }

                    // For Plan requests, check it's linked to a 4-session plan
                    if ($request->type === 'Plan') {
                        $plan = MentorshipPlan::where('service_id', $value)
                                    ->where('session_count', 4)
                                    ->exists();
                        
                        if (!$plan) {
                            $fail('This service is not configured as a 4-session plan');
                        }
                    }
                }
            ],
            'title' => 'required|string|max:255',
            'type' => ['required', Rule::in(['One_to_One', 'Group', 'Plan'])],
            'first_session_time' => [
                'required',
                'date',
                function ($attribute, $value, $fail) {
                    if (strtotime($value) <= time()) {
                        $fail('The first session time must be in the future');
                    }
                }
            ],
            'duration_minutes' => 'required|integer|min:30',
            'mentorship_plan_id' => [
                Rule::requiredIf($request->type === 'Plan'),
                'nullable',
                'exists:mentorship_plans,service_id',
                function ($attribute, $value, $fail) {
                    $plan = MentorshipPlan::find($value);
                    if ($plan && $plan->session_count != 4) {
                        $fail('The selected plan must have exactly 4 sessions');
                    }
                }
            ]
        ]);

        // Generate session schedule for Plan type
        $planSchedule = null;
        if ($validated['type'] === 'Plan') {
            $planSchedule = [];
            $sessionTime = new \DateTime($validated['first_session_time']);
            
            for ($i = 0; $i < 4; $i++) {
                $planSchedule[] = $sessionTime->format('Y-m-d H:i:s');
                $sessionTime->modify('+7 days'); // Weekly sessions
            }
        }

        $mentorshipRequest = MentorshipRequest::create([
            'trainee_id' => Auth::id(),
            'coach_id' => Service::find($validated['service_id'])->coach_id,
            'service_id' => $validated['service_id'],
            'title' => $validated['title'],
            'type' => $validated['type'],
            'first_session_time' => $validated['first_session_time'],
            'duration_minutes' => $validated['duration_minutes'],
            'plan_schedule' => $planSchedule,
            'mentorship_plan_id' => $validated['mentorship_plan_id'] ?? null,
            'status' => 'pending'
        ]);

        $response = [
            'status' => 'success',
            'message' => $validated['type'] === 'Plan' 
                ? '4-session plan created successfully' 
                : 'Mentorship request created successfully',
            'data' => $mentorshipRequest->load(['service', 'coach.user'])
        ];

        if ($validated['type'] === 'Plan') {
            $response['data']['scheduled_sessions'] = $planSchedule;
            $response['data']['total_hours'] = ($validated['duration_minutes'] * 4) / 60;
        }

        return response()->json($response, 201);
    }









    // public function store(Request $request)
    // {
    //     $validated = $request->validate([
    //         'service_id' => [
    //             'required',
    //             'exists:services,service_id',
    //             function ($attribute, $value, $fail) {
    //                 $service = Service::with('mentorshipPlan')->find($value);
                    
    //                 if (!$service) {
    //                     $fail('The selected service does not exist');
    //                     return;
    //                 }
    
    //                 if ($request->input('type') === 'Plan' && (!$service->mentorshipPlan || $service->mentorshipPlan->session_count != 4)) {
    //                     $fail('This service requires a valid 4-session plan');
    //                 }
    
    //                 if (!in_array($service->service_type, ['Mentorship', 'Group_Mentorship'])) {
    //                     $fail('This service type cannot accept mentorship requests');
    //                 }
    //             }
    //         ],
    //         'title' => 'required|string|max:255',
    //         'type' => ['required', Rule::in(['One_to_One', 'Group', 'Plan'])],
    //         'first_session_time' => 'required|date|after:now',
    //         'duration_minutes' => 'required|integer|min:30',
    //         'group_mentorship_id' => [
    //             Rule::requiredIf($request->type === 'Group'),
    //             'nullable',
    //             'exists:group_mentorships,service_id'
    //         ],
    //         'mentorship_plan_id' => [
    //             Rule::requiredIf($request->type === 'Plan'),
    //             'nullable',
    //             'exists:mentorship_plans,service_id',
    //             function ($attribute, $value, $fail) {
    //                 $plan = MentorshipPlan::find($value);
    //                 if ($plan && $plan->session_count != 4) {
    //                     $fail('The selected plan must have exactly 4 sessions');
    //                 }
    //             }
    //         ]
    //     ]);
    
    //     $service = Service::findOrFail($validated['service_id']);
    
    //     // Generate session schedule for Plan type
    //     $planSchedule = null;
    //     if ($validated['type'] === 'Plan') {
    //         $planSchedule = [];
    //         $sessionTime = new \DateTime($validated['first_session_time']);
            
    //         for ($i = 0; $i < 4; $i++) {
    //             $planSchedule[] = $sessionTime->format('Y-m-d H:i:s');
    //             $sessionTime->modify('+7 days'); // Weekly sessions
    //         }
    //     }
    
    //     $mentorshipRequest = MentorshipRequest::create([
    //         'trainee_id' => Auth::id(),
    //         'coach_id' => $service->coach_id,
    //         'service_id' => $service->service_id,
    //         'title' => $validated['title'],
    //         'type' => $validated['type'],
    //         'first_session_time' => $validated['first_session_time'],
    //         'duration_minutes' => $validated['duration_minutes'],
    //         'plan_schedule' => $planSchedule,
    //         'group_mentorship_id' => $validated['group_mentorship_id'] ?? null,
    //         'mentorship_plan_id' => $validated['mentorship_plan_id'] ?? null,
    //         'status' => 'pending'
    //     ]);
    
    //     $response = [
    //         'status' => 'success',
    //         'message' => 'Mentorship request created successfully',
    //         'data' => $mentorshipRequest->load(['service', 'coach.user'])
    //     ];
    
    //     // Add session details for Plan type
    //     if ($validated['type'] === 'Plan') {
    //         $response['data']['scheduled_sessions'] = $planSchedule;
    //         $response['data']['total_hours'] = ($validated['duration_minutes'] * 4) / 60;
    //         $response['message'] = '4-session plan created successfully';
    //     }
    
    //     return response()->json($response, 201);
    // }


















    // public function store(Request $request)
    // {
    //     $validated = $request->validate([
    //         'service_id' => [
    //             'required',
    //             'exists:services,service_id',
    //             function ($attribute, $value, $fail) {
    //                 $service = Service::find($value);
    //                 if (!in_array($service->service_type, ['Mentorship', 'Group_Mentorship'])) {
    //                     $fail('This service type cannot accept mentorship requests');
    //                 }
    //             }
    //         ],
    //         'title' => 'required|string|max:255',
    //         'type' => ['required', Rule::in(['One_to_One', 'Group', 'Plan'])],
    //         'first_session_time' => 'required|date|after:now',
    //         'duration_minutes' => 'required|integer|min:30',
    //         'group_mentorship_id' => [
    //             Rule::requiredIf($request->type === 'Group'),
    //             'nullable',
    //             'exists:group_mentorships,service_id'
    //         ],
    //         'mentorship_plan_id' => [
    //             Rule::requiredIf($request->type === 'Plan'),
    //             'nullable',
    //             'exists:mentorship_plans,service_id'
    //         ]
    //     ]);

    //     $service = Service::findOrFail($validated['service_id']);

    //     $mentorshipRequest = MentorshipRequest::create([
    //         'trainee_id' => Auth::id(),
    //         'coach_id' => $service->coach_id,
    //         'service_id' => $service->service_id,
    //         'title' => $validated['title'],
    //         'type' => $validated['type'],
    //         'first_session_time' => $validated['first_session_time'],
    //         'duration_minutes' => $validated['duration_minutes'],
    //         'plan_schedule' => $validated['type'] === 'Plan' ? $this->generatePlanSchedule($validated['first_session_time']) : null,
    //         'group_mentorship_id' => $validated['group_mentorship_id'] ?? null,
    //         'mentorship_plan_id' => $validated['mentorship_plan_id'] ?? null,
    //         'status' => 'pending'
    //     ]);

    //     return response()->json([
    //         'status' => 'success',
    //         'message' => 'Mentorship request created successfully',
    //         'data' => $mentorshipRequest->load(['service', 'coach.user'])
    //     ], 201);
    // }

    // Trainee: View their requests (all statuses)
    public function traineeIndex()
    {
        $requests = MentorshipRequest::with(['service', 'coach.user'])
            ->where('trainee_id', Auth::id())
            ->latest()
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $requests->groupBy('status') // Group by status for easier frontend handling
        ]);
    }

    // Coach: View pending requests
    public function coachPendingRequests()
    {
        $requests = MentorshipRequest::with(['service', 'trainee.user'])
            ->where('coach_id', Auth::id())
            ->where('status', 'pending')
            ->latest()
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $requests
        ]);
    }

    // Coach: Accept a request
    public function acceptRequest($id)
    {
        $request = MentorshipRequest::where('coach_id', Auth::id())
            ->findOrFail($id);

        $request->update(['status' => 'accepted']);

        return response()->json([
            'status' => 'success',
            'message' => 'Request accepted successfully',
            'data' => $request->fresh(['service', 'trainee.user'])
        ]);
    }

    // Coach: Reject a request
    public function rejectRequest($id)
    {
        $request = MentorshipRequest::where('coach_id', Auth::id())
            ->findOrFail($id);

        $request->update(['status' => 'rejected']);

        return response()->json([
            'status' => 'success',
            'message' => 'Request rejected successfully',
            'data' => $request->fresh(['service', 'trainee.user'])
        ]);
    }

    // Generate plan schedule for Plan type requests
    private function generatePlanSchedule($firstSessionTime)
    {
        $sessions = [];
        $date = new \DateTime($firstSessionTime);
        
        for ($i = 0; $i < 4; $i++) {
            $sessions[] = $date->format('Y-m-d H:i:s');
            $date->modify('+1 week');
        }
        
        return $sessions;
    }
}

