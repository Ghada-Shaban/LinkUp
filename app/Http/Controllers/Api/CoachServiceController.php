<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\Coach;
use App\Models\Mentorship;
use App\Models\MentorshipPlan;
use App\Models\MentorshipSession;
use App\Models\GroupMentorship;
use App\Models\MockInterview;
use App\Models\Price;
use Illuminate\Http\Request;
use App\Http\Resources\ServiceResource;
use App\Http\Resources\MentorshipPlanResource;
use App\Http\Resources\MentorshipSessionResource;
use App\Http\Resources\GroupMentorshipResource;
use App\Http\Resources\MockInterviewResource;

class CoachServiceController extends Controller
{

    public function getServicesCount($coachId)
{
    $coach = Coach::findOrFail($coachId);

    if (auth()->user()->User_ID != $coachId) {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    $services = Service::where('coach_id', $coachId)->get();

    $countData = [
        'all' => [
            'count' => $services->count()
        ],
        'mentorship_plans' => [
            'count' => $services->where('service_type', 'Mentorship')
                ->whereHas('mentorship.mentorshipPlan')
                ->count()
        ],
        'mentorship' => [ // قسم واحد لكل الـ Mentorship
            'count' => $services->where('service_type', 'Mentorship')->count(),
            
        ],
        'group_mentorship' => [
            'count' => $services->where('service_type', 'Group_Mentorship')->count()
        ],
        'mock_interview' => [
            'count' => $services->where('service_type', 'Mock_Interview')->count()
        ],
    ];

    return response()->json($countData);
}

    public function getServices(Request $request, $coachId)
    {
        $coach = Coach::findOrFail($coachId);

        if (auth()->user()->User_ID != $coachId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $allServices = $coach->services()
            ->with(['mentorship.mentorshipPlan', 'mentorship.mentorshipSession', 'groupMentorship', 'mockInterview', 'price'])
            ->get();

        $mentorshipServices = $coach->services()
            ->where('service_type', 'Mentorship')
            ->with(['mentorship.mentorshipPlan', 'mentorship.mentorshipSession', 'price'])
            ->get();

        $mentorshipPlans = $mentorshipServices->filter(function ($service) {
            return $service->mentorship && $service->mentorship->mentorshipPlan;
        });

        $mentorshipSessions = $mentorshipServices->filter(function ($service) {
            return $service->mentorship && $service->mentorship->mentorshipSession;
        });

        $groupMentorshipServices = $coach->services()
            ->where('service_type', 'Group_Mentorship')
            ->with(['groupMentorship', 'price'])
            ->get();

        $mockInterviewServices = $coach->services()
            ->where('service_type', 'Mock_Interview')
            ->with(['mockInterview', 'price'])
            ->get();

        return response()->json([
            'all' => [
                
                'services' => ServiceResource::collection($allServices),
            ],
            'mentorship_plans' => [
               
                'services' => MentorshipPlanResource::collection($mentorshipPlans),
            ],
            'mentorship_sessions' => [
                
                'services' => MentorshipSessionResource::collection($mentorshipSessions),
            ],
            'group_mentorship' => [
                
                'services' => GroupMentorshipResource::collection($groupMentorshipServices),
            ],
            'mock_interview' => [
                
                'services' => MockInterviewResource::collection($mockInterviewServices),
            ],
        ]);
    }

    public function createService(Request $request, $coachId)
    {
        $coach = Coach::findOrFail($coachId);

        if (auth()->user()->User_ID != $coachId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'service_type' => 'required|in:Mentorship,Mock_Interview,Group_Mentorship',
            'price' => 'required|numeric',
            'mentorship_type' => 'required_if:service_type,Mentorship|in:CV Review,project Assessment,Linkedin Optimization,Mentorship plan',
            'title' => 'required_if:mentorship_type,Mentorship plan|string|max:255',

            'interview_type' => 'required_if:service_type,Mock_Interview|in:Technical Interview,Soft Skills,Comprehensive Preparation',
            'interview_level' => 'required_if:service_type,Mock_Interview|in:Junior,Mid-Level,Senior,Premium (FAANG)',

            'title' => 'required_if:service_type,Group_Mentorship',
            'description' => 'required_if:service_type,Group_Mentorship',
            'day' => 'required_if:service_type,Group_Mentorship|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            'start_time' => 'required_if:service_type,Group_Mentorship|date_format:H:i',
        ]);

        $service = Service::create([
            'coach_id' => $coachId,
            'service_type' => $request->service_type,
            'admin_id' => '1'
        ]);

        $coach->services()->attach($service->service_id);

        if ($request->service_type === 'Mentorship') {
            Mentorship::create([
                'service_id' => $service->service_id,
            ]);

            if ($request->mentorship_type === 'Mentorship plan') {
                MentorshipPlan::create([
                    'service_id' => $service->service_id,
                    'title' => $request->title,
                ]);
            } else {
                MentorshipSession::create([
                    'service_id' => $service->service_id,
                    'session_type' => $request->mentorship_type,
                ]);
            }
        } elseif ($request->service_type === 'Mock_Interview') {
            MockInterview::create([
                'service_id' => $service->service_id,
                'interview_type' => $request->interview_type,
                'interview_level' => $request->interview_level
            ]);
        } elseif ($request->service_type === 'Group_Mentorship') {
            GroupMentorship::create([
                'service_id' => $service->service_id,
                'title' => $request->title,
                'description' => $request->description,
                'day' => $request->day,
                'start_time' => $request->start_time
            ]);
        }

        Price::create([
            'service_id' => $service->service_id,
            'price' => $request->price
        ]);

        return response()->json(['message' => 'Service created successfully', 'service' => new ServiceResource($service)], 201);
    }

    public function updateService(Request $request, $coachId, $serviceId)
    {
        $coach = Coach::findOrFail($coachId);

        if (auth()->user()->User_ID != $coachId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $service = Service::where('service_id', $serviceId)
            ->where('coach_id', $coachId)
            ->firstOrFail();

        $request->validate([
            'price' => 'required|numeric',
            'mentorship_type' => 'required_if:service_type,Mentorship|in:CV Review,project Assessment,Linkedin Optimization,Mentorship plan',
            'title' => 'required_if:mentorship_type,Mentorship plan|string|max:255',

            'interview_type' => 'required_if:service_type,Mock_Interview|in:Technical Interview,Soft Skills,Comprehensive Preparation',
            'interview_level' => 'required_if:service_type,Mock_Interview|in:Junior,Mid-Level,Senior,Premium (FAANG)',

            'title' => 'required_if:service_type,Group_Mentorship',
            'description' => 'required_if:service_type,Group_Mentorship',
            'day' => 'required_if:service_type,Group_Mentorship|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            'start_time' => 'required_if:service_type,Group_Mentorship|date_format:H:i',
        ]);

        $service->price()->update(['price' => $request->price]);

        if ($service->service_type === 'Mentorship') {
            if ($service->mentorship->mentorshipPlan) {
                $service->mentorship->mentorshipPlan()->update([
                    'title' => $request->title,
                ]);
            } else {
                $service->mentorship->mentorshipSession()->update([
                    'session_type' => $request->mentorship_type,
                ]);
            }
        } elseif ($service->service_type === 'Mock_Interview') {
            $service->mockInterview()->update([
                'interview_type' => $request->interview_type,
                'interview_level' => $request->interview_level
            ]);
        } elseif ($service->service_type === 'Group_Mentorship') {
            $service->groupMentorship()->update([
                'title' => $request->title,
                'description' => $request->description,
                'day' => $request->day,
                'start_time' => $request->start_time
            ]);
        }

        return response()->json(['message' => 'Service updated successfully']);
    }

    public function joinGroupMentorship(Request $request, $groupMentorshipId)
{
    $groupMentorship = GroupMentorship::findOrFail($groupMentorshipId);

    // التحقق من عدد الأماكن المتاحة
    if ($groupMentorship->available_slots <= 0) {
        return response()->json(['message' => 'This group mentorship is full.'], 400);
    }

    // زيادة current_participants بمقدار 1
    $groupMentorship->update(['current_participants' => $groupMentorship->current_participants + 1]);

    return response()->json([
        'message' => 'Successfully joined the group mentorship.',
        'available_slots' => $groupMentorship->available_slots - 1
    ]);
}

    public function deleteService(Request $request, $coachId, $serviceId)
    {
        $coach = Coach::findOrFail($coachId);

        if (auth()->user()->User_ID != $coachId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $service = Service::where('service_id', $serviceId)
            ->where('coach_id', $coachId)
            ->firstOrFail();

        $service->delete();

        return response()->json(['message' => 'Service deleted successfully']);
    }
}
