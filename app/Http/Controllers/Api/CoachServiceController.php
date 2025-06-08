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

    return DB::transaction(function () use ($request, $service, $coachId) {
        $request->validate([
            'service_type' => 'sometimes|in:Mentorship,Mock_Interview,Group_Mentorship',
            'price' => 'required|numeric',
            'mentorship_type' => 'required_if:service_type,Mentorship|in:Mentorship plan,Mentorship session',
            'session_type' => 'required_if:mentorship_type,Mentorship session|in:CV Review,project Assessment,Linkedin Optimization',
            'title' => [
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($request, $service) {
                    $currentType = $request->input('service_type', $service->service_type);
                    $currentMentorshipType = $request->input('mentorship_type', $service->mentorship->mentorship_type ?? '');
                    if ($currentType === 'Mentorship' && $currentMentorshipType === 'Mentorship plan' && empty($value)) {
                        $fail('The title field is required when mentorship type is Mentorship plan.');
                    }
                    if ($currentType === 'Group_Mentorship' && empty($value)) {
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

        $newServiceType = $request->input('service_type', $service->service_type);
        
        // Check if service type is changing
        if ($newServiceType !== $service->service_type) {
            // Create new service instead of updating existing one
            // Copy all necessary fields from original service
            $serviceData = [
                'coach_id' => $coachId,
                'service_type' => $newServiceType,
                'admin_id' => $service->admin_id,
                'status' => $service->status ?? 'active',
            ];
            
            // Copy other fields that might exist in the original service
            $fieldsToKeep = ['name', 'description', 'category', 'duration', 'max_participants'];
            foreach ($fieldsToKeep as $field) {
                if (isset($service->$field)) {
                    $serviceData[$field] = $service->$field;
                }
            }
            
            $newService = Service::create($serviceData);
            
            // Use the new service for further operations
            $targetService = $newService;
        } else {
            // Keep using existing service if type hasn't changed
            $targetService = $service;
        }

        // Create related models based on service type
        if ($newServiceType === 'Mentorship') {
            $mentorshipType = $request->input('mentorship_type', 'Mentorship plan');
            
            // Create mentorship record first and make sure service_id is set
            $mentorship = $targetService->mentorship()->create([
                'mentorship_type' => $mentorshipType, 
                'service_id' => $targetService->service_id
            ]);
            
            // Refresh the mentorship to get the latest data
            $mentorship->refresh();

            if ($mentorshipType === 'Mentorship plan') {
                $mentorship->mentorshipPlan()->create([
                    'service_id' => $mentorship->service_id,
                    'title' => $request->input('title')
                ]);
            } else {
                // For mentorship sessions, check what service_id should be used
                // If the foreign key points to mentorships.service_id, use that
                // If it points to mentorships.id, use mentorship_id only
                $sessionData = [
                    'mentorship_id' => $mentorship->id,
                    'session_type' => $request->input('session_type')
                ];
                
                // Only add service_id if the foreign key constraint expects it
                // Based on the error, it seems like it expects mentorships.service_id
                if ($mentorship->service_id) {
                    $sessionData['service_id'] = $mentorship->service_id;
                }
                
                $mentorship->mentorshipSession()->create($sessionData);
            }
        } elseif ($newServiceType === 'Mock_Interview') {
            $targetService->mockInterview()->create([
                'service_id' => $targetService->service_id,
                'interview_type' => $request->input('interview_type'),
                'interview_level' => $request->input('interview_level'),
            ]);
        } elseif ($newServiceType === 'Group_Mentorship') {
            $targetService->groupMentorship()->create([
                'service_id' => $targetService->service_id,
                'title' => $request->input('title'),
                'description' => $request->input('description'),
                'day' => $request->input('day'),
                'start_time' => $request->input('start_time'),
            ]);
        }

        // Create price for the target service
        if ($request->has('price')) {
            $targetService->price()->create([
                'service_id' => $targetService->service_id,
                'price' => $request->price
            ]);
        }

        $targetService->load('price');
        
        $message = $newServiceType !== $service->service_type 
            ? 'New service created successfully' 
            : 'Service updated successfully';
            
        return response()->json([
            'message' => $message,
            'service' => new ServiceResource($targetService),
            'is_new_service' => $newServiceType !== $service->service_type
        ], 200);
    });
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
        'is_active' => $newParticipantCount >= 2, 
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
