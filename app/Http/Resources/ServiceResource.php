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
            'price' => $this->whenLoaded('price', fn () => $this->price->price),
        ];

        if ($this->relationLoaded('mentorship') && $this->mentorship) {
            if ($this->mentorship->mentorship_type === 'Mentorship plan' && $this->relationLoaded('mentorship.mentorshipPlan') && $this->mentorship->mentorshipPlan) {
                $data['mentorship'] = [
                    'mentorship_plan' => [
                        'title' => $this->mentorship->mentorshipPlan->title ?? null,
                        'duration' => '60 minutes',
                        'no.of sessions' => '4 sessions',
                    ],
                ];
            } elseif ($this->mentorship->mentorship_type === 'Mentorship session' && $this->relationLoaded('mentorship.mentorshipSession') && $this->mentorship->mentorshipSession) {
                $data['mentorship'] = [
                    'mentorship_session' => [
                        'session_type' => $this->mentorship->mentorshipSession->session_type ?? null,
                        'duration' => '60 minutes',
                        'no.of sessions' => '1 session',
                    ],
                ];
            }
        }

        if ($this->relationLoaded('groupMentorship') && $this->groupMentorship) {
            $data['group_mentorship'] = [
                'title' => $this->groupMentorship->title ?? null,
                'description' => $this->groupMentorship->description ?? null,
                'day' => $this->groupMentorship->day ?? null,
                'start_time' => $this->groupMentorship->start_time ?? null,
                'duration' => '60 minutes',
                'no_of_sessions' => '4 sessions',
                'min_participants' => $this->groupMentorship->min_participants ?? 2,
                'max_participants' => $this->groupMentorship->max_participants ?? 5,
                'available_slots' => $this->groupMentorship->available_slots ?? 
                    (($this->groupMentorship->max_participants ?? 5) - ($this->groupMentorship->current_participants ?? 0)),
            ];
        }

        if ($this->relationLoaded('mockInterview') && $this->mockInterview) {
            $data['mock_interview'] = [
                'interview_type' => $this->mockInterview->interview_type ?? null,
                'interview_level' => $this->mockInterview->interview_level ?? null,
                'duration' => '60 minutes',
                'no_of_sessions' => '1 session',
            ];
        }

        return $data;
    }
}
