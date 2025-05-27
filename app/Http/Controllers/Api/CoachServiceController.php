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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CoachServiceController extends Controller
{
public function getServicesCount($coachId)
{  
    $coach = Coach::findOrFail($coachId);
    $services = Service::where('coach_id', $coachId)
                       ->whereNull('deleted_at')
                       ->with('price')
                       ->get();

        $countData = [
            'all' => [
                'count' => $services->count()
            ],
            'mentorship' => [
                'count' => $services->where('service_type', 'Mentorship')->count()
            ],
            'group_mentorship' => [
                'count' => $services->where('service_type', 'Group_Mentorship')->count()
            ],
            'mock_interview' => [
                'count' => $services->where('service_type', 'Mock_Interview')->count()
            ]
        ];

        return response()->json($countData);
    }

 
    public function getServices(Request $request, $coachId)
    {
        $coach = Coach::findOrFail($coachId);
        $serviceType = $request->query('service_type', 'all');

        switch ($serviceType) {
            case 'all':
                return $this->getAllServices($request, $coachId);
            case 'mentorship-plans':
                return $this->getMentorshipPlans($request, $coachId);
            case 'mentorship-sessions':
                return $this->getMentorshipSessions($request, $coachId);
            case 'group-mentorship':
                return $this->getGroupMentorshipServices($request, $coachId);
            case 'mock-interview':
                return $this->getMockInterviewServices($request, $coachId);
            default:
                return response()->json(['message' => 'Invalid service type'], 400);
        }
    }

  
    private function getAllServices(Request $request, $coachId)
    {
        $coach = Coach::findOrFail($coachId);

        $services = $coach->services()
            ->with(['mentorship.mentorshipPlan', 'mentorship.mentorshipSession', 'groupMentorship', 'mockInterview', 'price'])
            ->get();

        return response()->json([
            'services' => ServiceResource::collection($services)
        ]);
    }

   
   private function getMentorshipPlans(Request $request, $coachId)
{
    $services = Service::where('coach_id', $coachId)
        ->where('service_type', 'Mentorship')
        ->whereHas('mentorship', function($query) {
            $query->where('mentorship_type', 'Mentorship plan');
        })
        ->whereHas('mentorship.mentorshipPlan') 
        ->with(['mentorship.mentorshipPlan', 'price'])
        ->get();
    
    return response()->json([
        'services' => MentorshipPlanResource::collection($services)
    ]);
}

   
    private function getMentorshipSessions(Request $request, $coachId)
    {
        $services = Service::where('coach_id', $coachId)
            ->where('service_type', 'Mentorship')
            ->whereHas('mentorship', function($query) {
                $query->where('mentorship_type', 'Mentorship session');
            })
            ->whereHas('mentorship.mentorshipSession') 
            ->with(['mentorship.mentorshipSession', 'price'])
            ->get();
        
        return response()->json([
            'services' => MentorshipSessionResource::collection($services)
        ]);
    }

  
   private function getGroupMentorshipServices(Request $request, $coachId)
{
    $services = Service::where('coach_id', $coachId)
        ->where('service_type', 'Group_Mentorship')
        ->whereHas('groupMentorship') 
        ->with(['groupMentorship', 'price'])
        ->get();

        return response()->json([
            'services' => GroupMentorshipResource::collection($services)
        ]);
    }

   
    private function getMockInterviewServices(Request $request, $coachId)
    {
        $services = Service::where('coach_id', $coachId)
            ->where('service_type', 'Mock_Interview')
            ->whereHas('mockInterview') 
            ->with(['mockInterview', 'price'])
            ->get();
    
        return response()->json([
            'services' => MockInterviewResource::collection($services)
        ]);
    }
public function createService(Request $request, $coachId)
    {
        $coach = Coach::findOrFail($coachId);

        $request->validate([
            'service_type' => 'required|in:Mentorship,Mock_Interview,Group_Mentorship',
            'price' => 'required|numeric',
            'mentorship_type' => 'required_if:service_type,Mentorship|in:Mentorship plan,Mentorship session',
            'session_type' => 'required_if:mentorship_type,Mentorship session|in:CV Review,project Assessment,Linkedin Optimization',
            'title' => [
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->service_type === 'Mentorship' && $request->mentorship_type === 'Mentorship plan' && empty($value)) {
                        $fail('The title field is required when mentorship type is Mentorship plan.');
                    }
                    if ($request->service_type === 'Group_Mentorship' && empty($value)) {
                        $fail('The title field is required when service type is Group Mentorship.');
                    }
                },
            ],
            'interview_type' => 'required_if:service_type,Mock_Interview|in:Technical Interview,Soft Skills,Comprehensive Preparation',
            'interview_level' => 'required_if:service_type,Mock_Interview|in:Junior,Mid-Level,Senior,Premium (FAANG)',
            'description' => 'required_if:service_type,Group_Mentorship',
            'day' => 'required_if:service_type,Group_Mentorship|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            'start_time' => 'required_if:service_type,Group_Mentorship|date_format:H:i',
        ]);

        \Log::info('Creating new service', [
            'coach_id' => $coachId,
            'service_type' => $request->service_type,
            'mentorship_type' => $request->mentorship_type,
            'session_type' => $request->session_type,
        ]);

        try {
            $service = Service::create([
                'coach_id' => $coachId,
                'service_type' => $request->service_type,
                'admin_id' => '1'
            ]);

             $coach->services()->attach($service->service_id);
            if ($request->service_type === 'Mentorship') {
              
                $mentorshipType = ($request->mentorship_type === 'Mentorship plan') ? 'Mentorship plan' : 'Mentorship session';
                $mentorship = Mentorship::create([
                    'service_id' => $service->service_id,
                    'mentorship_type' => $mentorshipType
                ]);


                if ($mentorshipType === 'Mentorship plan') {
                    MentorshipPlan::create([
                        'service_id' => $service->service_id,
                        'title' => $request->title,
                    ]);
                } else {
                    MentorshipSession::create([
                        'service_id' => $service->service_id,
                        'session_type' => $request->session_type,
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
                    'start_time' => $request->start_time,
                    'trainee_ids' => json_encode([]),
                ]);
            }

            Price::create([
                'service_id' => $service->service_id,
                'price' => $request->price
            ]);
            $service->load('price');

            return response()->json(['message' => 'Service created successfully', 'service' => new ServiceResource($service)], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error creating service', 'error' => $e->getMessage()], 500);
        }
    }
    
  public function updateService(Request $request, $coachId, $serviceId)
{
    $coach = Coach::findOrFail($coachId);

    $service = Service::where('service_id', $serviceId)
        ->where('coach_id', $coachId)
        ->firstOrFail();

    $request->validate([
        'service_type' => 'sometimes|in:Mentorship,Mock_Interview,Group_Mentorship',
        'price' => 'required|numeric',
        'mentorship_type' => [
            'required_if:service_type,Mentorship',
            'in:Mentorship plan,Mentorship session',
            function ($attribute, $value, $fail) use ($request, $service) {
                if ($service->service_type === 'Mentorship' && empty($value)) {
                    $fail('The mentorship type is required for Mentorship services.');
                }
            },
        ],
        'session_type' => [
            'required_if:mentorship_type,Mentorship session',
            'in:CV Review,project Assessment,Linkedin Optimization',
            function ($attribute, $value, $fail) use ($request, $service) {
                $mentorshipType = $request->mentorship_type ?? $service->mentorship->mentorship_type;
                if ($service->service_type === 'Mentorship' && $mentorshipType === 'Mentorship session' && empty($value)) {
                    $fail('The session type is required when mentorship type is Mentorship session.');
                }
            },
        ],
        'title' => [
            'string',
            'max:255',
            function ($attribute, $value, $fail) use ($request, $service) {
                if ($service->service_type === 'Mentorship' && $service->mentorship->mentorship_type === 'Mentorship plan' && empty($value)) {
                    $fail('The title field is required when mentorship type is Mentorship plan.');
                }
                if ($service->service_type === 'Group_Mentorship' && empty($value)) {
                    $fail('The title field is required when service type is Group Mentorship.');
                }
            },
        ],
        'interview_type' => [
            'required_if:service_type,Mock_Interview',
            'in:Technical Interview,Soft Skills,Comprehensive Preparation',
            function ($attribute, $value, $fail) use ($request, $service) {
                if ($service->service_type === 'Mock_Interview' && empty($value)) {
                    $fail('The interview type is required for Mock Interview services.');
                }
            },
        ],
        'interview_level' => [
            'required_if:service_type,Mock_Interview',
            'in:Junior,Mid-Level,Senior,Premium (FAANG)',
            function ($attribute, $value, $fail) use ($request, $service) {
                if ($service->service_type === 'Mock_Interview' && empty($value)) {
                    $fail('The interview level is required for Mock Interview services.');
                }
            },
        ],
        'description' => [
            'required_if:service_type,Group_Mentorship',
            function ($attribute, $value, $fail) use ($request, $service) {
                if ($service->service_type === 'Group_Mentorship' && empty($value)) {
                    $fail('The description is required for Group Mentorship services.');
                }
            },
        ],
        'day' => [
            'required_if:service_type,Group_Mentorship',
            'in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            function ($attribute, $value, $fail) use ($request, $service) {
                if ($service->service_type === 'Group_Mentorship' && empty($value)) {
                    $fail('The day is required for Group Mentorship services.');
                }
            },
        ],
        'start_time' => [
            'required_if:service_type,Group_Mentorship',
            'date_format:H:i',
            function ($attribute, $value, $fail) use ($request, $service) {
                if ($service->service_type === 'Group_Mentorship' && empty($value)) {
                    $fail('The start time is required for Group Mentorship services.');
                }
            },
        ],
    ]);

   
    if ($request->has('price')) {
        $service->price()->updateOrCreate([], ['price' => $request->price]);
    }

   
    $serviceType = $request->service_type ?? $service->service_type;

    if ($serviceType === 'Mentorship') {
        
        if ($request->has('mentorship_type')) {
            $service->mentorship->update(['mentorship_type' => $request->mentorship_type]);
            if ($request->mentorship_type === 'Mentorship plan') {
                $service->mentorship->mentorshipSession()->delete();
                $service->mentorship->mentorshipPlan()->updateOrCreate([], ['title' => $request->title]);
                $hasPlan = true;
            } else {
                $service->mentorship->mentorshipPlan()->delete();
                $service->mentorship->mentorshipSession()->updateOrCreate([], ['session_type' => $request->session_type]);
                $hasPlan = false;
            }
        } else {
            $hasPlan = $service->mentorship->mentorshipPlan()->exists();
            if ($hasPlan) {
                $service->mentorship->mentorshipPlan()->update([
                    'title' => $request->title,
                ]);
            } else {
                $service->mentorship->mentorshipSession()->update([
                    'session_type' => $request->session_type,
                ]);
            }
        }
        Log::info('Updating service ID: ' . $service->service_id . ' Has Plan: ' . ($hasPlan ? 'Yes' : 'No'));
    } elseif ($serviceType === 'Mock_Interview') {
        $service->mockInterview()->update([
            'interview_type' => $request->interview_type,
            'interview_level' => $request->interview_level
        ]);
    } elseif ($serviceType === 'Group_Mentorship') {
        $service->groupMentorship()->update([
            'title' => $request->has('title') ? $request->title : $service->groupMentorship->title,
            'description' => $request->has('description') ? $request->description : $service->groupMentorship->description,
            'day' => $request->has('day') ? $request->day : $service->groupMentorship->day,
            'start_time' => $request->has('start_time') ? $request->start_time : $service->groupMentorship->start_time
        ]);
    }

    return response()->json(['message' => 'Service updated successfully']);
}
    
 
    public function joinGroupMentorship(Request $request, $coachId, $groupMentorshipId)
    {
        
        $coach = Coach::findOrFail($coachId);

      
        $groupMentorship = GroupMentorship::where('service_id', $groupMentorshipId)->firstOrFail();

      
        $service = Service::where('service_id', $groupMentorship->service_id)
            ->where('coach_id', $coach->User_ID)
            ->where('service_type', 'Group_Mentorship')
            ->firstOrFail();

    
        $trainee = Auth::guard('api')->user()->trainee;

       
        if (is_null($groupMentorship->max_participants)) {
            return response()->json(['message' => 'Max participants not set for this group mentorship.'], 400);
        }

     
        $availableSlots = $groupMentorship->max_participants - $groupMentorship->current_participants;

      
        if ($availableSlots <= 0) {
            return response()->json(['message' => 'This group mentorship is full.', 'available_slots' => 0], 400);
        }
   
     
        $traineeIds = $groupMentorship->trainee_ids ? json_decode($groupMentorship->trainee_ids, true) : [];

       
        if (in_array($trainee->User_ID, $traineeIds)) {
            return response()->json(['message' => 'Trainee is already joined to this group mentorship', 'available_slots' => $availableSlots], 400);
        }

       
        $traineeIds[] = $trainee->User_ID;

     $newParticipantCount = $groupMentorship->current_participants + 1;
    $groupMentorship->update([
        'trainee_ids' => json_encode($traineeIds),
        'current_participants' => $newParticipantCount,
        'is_active' => $newParticipantCount >= 2, // لو عدد الـ trainees بقى 2 أو أكتر، الـ Group Mentorship هتبقى نشطة
    ]);

       
        $groupMentorship->refresh();

        
        $availableSlots = $groupMentorship->available_slots;

        return response()->json([
            'message' => 'Successfully joined the group mentorship.',
            'available_slots' => $availableSlots,
        'is_active' => $groupMentorship->is_active,
        'current_participants' => $groupMentorship->current_participants
    ], 200);
    }

    public function deleteService(Request $request, $coachId, $serviceId)
    {
        $coach = Coach::findOrFail($coachId);

        $service = Service::where('service_id', $serviceId)
            ->where('coach_id', $coachId)
            ->firstOrFail();

        $service->delete();

        return response()->json(['message' => 'Service deleted successfully']);
    }

    
   
}
