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

    // إضافة debug logging
    Log::info('Update Service Request Data:', [
        'service_id' => $serviceId,
        'service_type' => $request->service_type,
        'mentorship_type' => $request->mentorship_type,
        'all_request_data' => $request->all()
    ]);

    $request->validate([
        'service_type' => 'sometimes|in:Mentorship,Mock_Interview,Group_Mentorship',
        'price' => 'required|numeric',
        'mentorship_type' => [
            'required_if:service_type,Mentorship',
            'in:Mentorship plan,Mentorship session',
            function ($attribute, $value, $fail) use ($request, $service) {
                $newServiceType = $request->service_type ?? $service->service_type;
                if ($newServiceType === 'Mentorship' && empty($value)) {
                    $fail('The mentorship type is required for Mentorship services.');
                }
            },
        ],
        'session_type' => [
            'required_if:mentorship_type,Mentorship session',
            'in:CV Review,project Assessment,Linkedin Optimization',
            function ($attribute, $value, $fail) use ($request, $service) {
                $newServiceType = $request->service_type ?? $service->service_type;
                $mentorshipType = $request->mentorship_type ?? ($service->mentorship ? $service->mentorship->mentorship_type : null);
                if ($newServiceType === 'Mentorship' && $mentorshipType === 'Mentorship session' && empty($value)) {
                    $fail('The session type is required when mentorship type is Mentorship session.');
                }
            },
        ],
        'title' => [
            'string',
            'max:255',
            function ($attribute, $value, $fail) use ($request, $service) {
                $newServiceType = $request->service_type ?? $service->service_type;
                $mentorshipType = $request->mentorship_type ?? ($service->mentorship ? $service->mentorship->mentorship_type : null);
                
                if ($newServiceType === 'Mentorship' && $mentorshipType === 'Mentorship plan' && empty($value)) {
                    $fail('The title field is required when mentorship type is Mentorship plan.');
                }
                if ($newServiceType === 'Group_Mentorship' && empty($value)) {
                    $fail('The title field is required when service type is Group Mentorship.');
                }
            },
        ],
        'interview_type' => [
            'required_if:service_type,Mock_Interview',
            'in:Technical Interview,Soft Skills,Comprehensive Preparation',
            function ($attribute, $value, $fail) use ($request, $service) {
                $newServiceType = $request->service_type ?? $service->service_type;
                if ($newServiceType === 'Mock_Interview' && empty($value)) {
                    $fail('The interview type is required for Mock Interview services.');
                }
            },
        ],
        'interview_level' => [
            'required_if:service_type,Mock_Interview',
            'in:Junior,Mid-Level,Senior,Premium (FAANG)',
            function ($attribute, $value, $fail) use ($request, $service) {
                $newServiceType = $request->service_type ?? $service->service_type;
                if ($newServiceType === 'Mock_Interview' && empty($value)) {
                    $fail('The interview level is required for Mock Interview services.');
                }
            },
        ],
        'description' => [
            'required_if:service_type,Group_Mentorship',
            function ($attribute, $value, $fail) use ($request, $service) {
                $newServiceType = $request->service_type ?? $service->service_type;
                if ($newServiceType === 'Group_Mentorship' && empty($value)) {
                    $fail('The description is required for Group Mentorship services.');
                }
            },
        ],
        'day' => [
            'required_if:service_type,Group_Mentorship',
            'in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            function ($attribute, $value, $fail) use ($request, $service) {
                $newServiceType = $request->service_type ?? $service->service_type;
                if ($newServiceType === 'Group_Mentorship' && empty($value)) {
                    $fail('The day is required for Group Mentorship services.');
                }
            },
        ],
        'start_time' => [
            'required_if:service_type,Group_Mentorship',
            'date_format:H:i',
            function ($attribute, $value, $fail) use ($request, $service) {
                $newServiceType = $request->service_type ?? $service->service_type;
                if ($newServiceType === 'Group_Mentorship' && empty($value)) {
                    $fail('The start time is required for Group Mentorship services.');
                }
            },
        ],
    ]);

    try {
        DB::beginTransaction();

        // تحديث الـ price
        if ($request->has('price')) {
            $service->price()->updateOrCreate([], ['price' => $request->price]);
        }

        // تحديد نوع الخدمة الجديد والقديم
        $oldServiceType = $service->service_type;
        $newServiceType = $request->service_type ?? $oldServiceType;

        // إضافة debug logging إضافي
        Log::info('Service Type Comparison:', [
            'old_service_type' => $oldServiceType,
            'new_service_type' => $newServiceType,
            'mentorship_type_in_request' => $request->mentorship_type,
            'current_mentorship_type' => $service->mentorship ? $service->mentorship->mentorship_type : 'null'
        ]);

        // إذا تغير نوع الخدمة، نحذف البيانات القديمة
        if ($oldServiceType !== $newServiceType) {
            $this->deleteOldServiceData($service, $oldServiceType);
            $service->update(['service_type' => $newServiceType]);
        }

        // إنشاء أو تحديث البيانات حسب النوع الجديد
        if ($newServiceType === 'Mentorship') {
            $this->handleMentorshipUpdate($service, $request);
        } elseif ($newServiceType === 'Mock_Interview') {
            $this->handleMockInterviewUpdate($service, $request);
        } elseif ($newServiceType === 'Group_Mentorship') {
            $this->handleGroupMentorshipUpdate($service, $request);
        }

        DB::commit();

        // إعادة تحميل العلاقات
        $service->refresh();
        $service->load('price', 'mentorship.mentorshipPlan', 'mentorship.mentorshipSession', 'groupMentorship', 'mockInterview');
        
        // تسجيل حالة الخدمة بعد التحديث
        Log::info('Service after update: ', [
            'service_id' => $service->service_id,
            'service_type' => $service->service_type,
            'mentorship_type' => $service->mentorship ? $service->mentorship->mentorship_type : 'null',
            'has_mentorship_plan' => $service->mentorship && $service->mentorship->mentorshipPlan ? true : false,
            'has_mentorship_session' => $service->mentorship && $service->mentorship->mentorshipSession ? true : false
        ]);
          $updatedCountData = $this->getServicesCount($coachId);
        return response()->json([
            'message' => 'Service updated successfully', 
            'service' => new ServiceResource($service)
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Error updating service: ' . $e->getMessage(), [
            'service_id' => $serviceId,
            'coach_id' => $coachId,
            'request_data' => $request->all(),
            'trace' => $e->getTraceAsString()
        ]);
        
        return response()->json([
            'message' => 'Error updating service', 
            'error' => $e->getMessage()
        ], 500);
    }
}

// دالة لحذف البيانات القديمة
private function deleteOldServiceData($service, $oldServiceType)
{
    switch ($oldServiceType) {
        case 'Mentorship':
            if ($service->mentorship) {
                $service->mentorship->mentorshipSession()->delete();
                $service->mentorship->mentorshipPlan()->delete();
                $service->mentorship->delete();
            }
            break;
        case 'Mock_Interview':
            $service->mockInterview()->delete();
            break;
        case 'Group_Mentorship':
            $service->groupMentorship()->delete();
            break;
    }
}

// دالة للتعامل مع تحديث Mentorship - محدثة
private function handleMentorshipUpdate($service, $request)
{
    // تحديد نوع المنتورشيب من الـ request أو الموجود حالياً
    $mentorshipType = $request->mentorship_type ?? ($service->mentorship ? $service->mentorship->mentorship_type : 'Mentorship session');
    
    Log::info('Handling Mentorship Update:', [
        'service_id' => $service->service_id,
        'requested_mentorship_type' => $request->mentorship_type,
        'determined_mentorship_type' => $mentorshipType
    ]);
    
    // إنشاء أو تحديث الـ mentorship مع النوع الصحيح
    $mentorship = $service->mentorship()->updateOrCreate(
        ['service_id' => $service->service_id],
        ['mentorship_type' => $mentorshipType]
    );
    
    Log::info('Mentorship record after updateOrCreate:', [
        'service_id' => $service->service_id,
        'mentorship_type' => $mentorship->mentorship_type
    ]);

    if ($mentorshipType === 'Mentorship plan') {
        // احذف أي mentorship session موجود
        MentorshipSession::where('service_id', $service->service_id)->delete();
        
        // إنشاء أو تحديث mentorship plan
        $mentorshipPlan = MentorshipPlan::updateOrCreate(
            ['service_id' => $service->service_id],
            ['title' => $request->title]
        );
        
        Log::info('Updated MentorshipPlan:', [
            'service_id' => $service->service_id,
            'title' => $request->title,
            'plan_id' => $mentorshipPlan->id ?? 'null'
        ]);
    } else {
        // احذف أي mentorship plan موجود
        MentorshipPlan::where('service_id', $service->service_id)->delete();
        
        // إنشاء أو تحديث mentorship session
        $mentorshipSession = MentorshipSession::updateOrCreate(
            ['service_id' => $service->service_id],
            ['session_type' => $request->session_type]
        );
        
        Log::info('Updated MentorshipSession:', [
            'service_id' => $service->service_id,
            'session_type' => $request->session_type,
            'session_id' => $mentorshipSession->id ?? 'null'
        ]);
    }
    
    Log::info('Completed Mentorship Update for service ID: ' . $service->service_id, [
        'mentorship_type' => $mentorshipType
    ]);
}

// دالة للتعامل مع تحديث Mock Interview
private function handleMockInterviewUpdate($service, $request)
{
    $service->mockInterview()->updateOrCreate(
        ['service_id' => $service->service_id],
        [
            'interview_type' => $request->interview_type,
            'interview_level' => $request->interview_level
        ]
    );
    
    Log::info('Updated Mock Interview for service ID: ' . $service->service_id, [
        'interview_type' => $request->interview_type,
        'interview_level' => $request->interview_level
    ]);
}

// دالة للتعامل مع تحديث Group Mentorship
private function handleGroupMentorshipUpdate($service, $request)
{
    $service->groupMentorship()->updateOrCreate(
        ['service_id' => $service->service_id],
        [
            'title' => $request->title,
            'description' => $request->description,
            'day' => $request->day,
            'start_time' => $request->start_time
        ]
    );
    
    Log::info('Updated Group Mentorship for service ID: ' . $service->service_id, [
        'title' => $request->title,
        'day' => $request->day,
        'start_time' => $request->start_time
    ]);
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
