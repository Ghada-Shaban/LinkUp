<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray(Request $request): array
    {
        return [
            'service_id' => $this->service_id,
            'service_type' => $this->service_type,
            'price' => $this->whenLoaded('price', fn() => number_format($this->price->price, 2)),
            'mentorship' => $this->when($this->service_type === 'Mentorship', function () {
                $mentorship = $this->whenLoaded('mentorship');
                if (!$mentorship) {
                    return null;
                }

                $data = [];
                if ($this->mentorship->mentorshipPlan) {
                    $data['mentorship_plan'] = [
                        'title' => $this->mentorship->mentorshipPlan->title,
                        'duration' => $this->mentorship->mentorshipPlan->duration,
                        'no_of_sessions' => $this->mentorship->mentorshipPlan->no_of_sessions,
                        'frequency' => $this->mentorship->mentorshipPlan->frequency,
                    ];
                }
                if ($this->mentorship->mentorshipSession) {
                    $data['mentorship_session'] = [
                        'session_type' => $this->mentorship->mentorshipSession->session_type,
                    ];
                }

                return $data;
            }),
            'group_mentorship' => $this->when($this->service_type === 'Group_Mentorship', function () {
                $groupMentorship = $this->whenLoaded('groupMentorship');
                if (!$groupMentorship) {
                    return null;
                }

                return [
                    'title' => $this->groupMentorship->title,
                    'description' => $this->groupMentorship->description,
                    'day' => $this->groupMentorship->day,
                    'start_time' => $this->groupMentorship->start_time,
                ];
            }),
            'mock_interview' => $this->when($this->service_type === 'Mock_Interview', function () {
                $mockInterview = $this->whenLoaded('mockInterview');
                if (!$mockInterview) {
                    return null;
                }

                return [
                    'interview_type' => $this->mockInterview->interview_type,
                    'interview_level' => $this->mockInterview->interview_level,
                ];
            }),
          
        ];
    }
}
