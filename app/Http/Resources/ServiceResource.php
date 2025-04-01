<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'service_id' => $this->service_id,
            'service_type' => $this->service_type,
            'price' => $this->price ? $this->price->price : null,
            'mentorship' => $this->when($this->service_type === 'Mentorship', function () {
                return [
                    'mentorship_plan' => $this->mentorship && $this->mentorship->mentorshipPlan ? [
                        'title' => $this->mentorship->mentorshipPlan->title,
                        'duration'=>'60 minutes',
                        'no.of sessions'=>'4 sessions',
                     
                    ] 
                    'mentorship_session' => $this->mentorship && $this->mentorship->mentorshipSession ? [
                        'session_type' => $this->mentorship->mentorshipSession->session_type,
                        'duration'=>'60 minutes',
                        'no.of sessions'=>'1 session',
                      
                    ] 
                ];
            }),
            'group_mentorship' => $this->when($this->service_type === 'Group_Mentorship', function () {
                return [
                    'title' => $this->groupMentorship ? $this->groupMentorship->title : null,
                    'description' => $this->groupMentorship ? $this->groupMentorship->description : null,
                    'day' => $this->groupMentorship ? $this->groupMentorship->day : null,
                    'start_time' => $this->groupMentorship ? $this->groupMentorship->start_time : null,
                    'duration'=>'60 minutes',
                    'no.of sessions'=>'4 sessions',
'                 min_participants' => $this->groupMentorship->min_participants,
                    'max_participants' => $this->groupMentorship->max_participants,
                    'available_slots' => $this->groupMentorship->available_slots,
                ];
            }),
            'mock_interview' => $this->when($this->service_type === 'Mock_Interview', function () {
                return [
                    'interview_type' => $this->mockInterview ? $this->mockInterview->interview_type : null,
                    'interview_level' => $this->mockInterview ? $this->mockInterview->interview_level : null,
                    'duration'=>'60 minutes',
                    'no.of sessions'=>'1 session',
                    
                ];
            }),
        ];
    }
}
