<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
{
    public function toArray($request)
    {
        $data = [
            'service_id' => $this->service_id,
            'service_type' => $this->service_type,
            'price' => $this->price ? $this->price->price : null,
        ];

     
        if ($this->service_type === 'Mentorship') {
            if ($this->mentorship) {
               if ($this->mentorship->mentorship_type === 'Mentorship plan' && $this->mentorship->mentorshipPlan) {
                    $data['mentorship'] = [
                        'mentorship_plan' => [
                            'title' => $this->mentorship->mentorshipPlan->title,
                            'duration' => '60 minutes',
                            'no_of_sessions' => '4 sessions',
                         'role'=> $this->mentorship->role,
                'career_phase' => $this->mentorship->career_phase
                        ]
                    ];
                } else if ($this->mentorship->mentorship_type === 'Mentorship session' && $this->mentorship->mentorshipSession) {
                    $data['mentorship'] = [
                        'mentorship_session' => [
                            'session_type' => $this->mentorship->mentorshipSession->session_type,
                            'duration' => '60 minutes',
                            'no_of_sessions' => '1 session',
                         'role'=> $this->mentorship->role,
                'career_phase' => $this->mentorship->career_phase
                        ]
                    ];
                }
            }
        }

        if ($this->service_type === 'Group_Mentorship' && $this->groupMentorship) {
            $data['group_mentorship'] = [
                'title' => $this->groupMentorship->title,
                'description' => $this->groupMentorship->description,
                'day' => $this->groupMentorship->day,
                'start_time' => $this->groupMentorship->start_time,
                'duration' => '60 minutes',
                'no_of_sessions' => '4 sessions',
                'min_participants' => $this->groupMentorship->min_participants ?? 2,
                'max_participants' => $this->groupMentorship->max_participants ?? 5,
                'available_slots' => $this->groupMentorship->available_slots ?? 
                    (($this->groupMentorship->max_participants ?? 5) - ($this->groupMentorship->current_participants ?? 0)),
                'role'=> $this->groupMentorship->role,
                'career_phase' => $this->groupMentorship->career_phase
            ];
        }

        if ($this->service_type === 'Mock_Interview' && $this->mockInterview) {
            $data['mock_interview'] = [
                'interview_type' => $this->mockInterview->interview_type,
                'interview_level' => $this->mockInterview->interview_level,
                'duration' => '60 minutes',
                'no_of_sessions' => '1 session',
            ];
        }

      
        return $data;
    }
}
