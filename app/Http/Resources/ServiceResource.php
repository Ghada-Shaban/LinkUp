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
          'mentorship_plan' => $this->when($this->service_type === 'Mentorship' && $this->mentorship && $this->mentorship->mentorshipPlan, function () {
    return [
        'title' => $this->mentorship->mentorshipPlan->title,
        'duration' => '60 minutes',
        'no.of sessions' => '4 sessions',
    ];
}),


   'mentorship_session' => $this->when($this->service_type === 'Mentorship' && $this->mentorship && $this->mentorship->mentorshipSession, function () {
    return [
        'session_type' => $this->mentorship->mentorshipSession->session_type,
        'duration' => '60 minutes',
        'no.of sessions' => '1 session',
    ];
}),


            'group_mentorship' => $this->when($this->service_type === 'Group_Mentorship' && $this->groupMentorship, function () {
                return [
                    'title' => $this->groupMentorship->title,
                    'description' => $this->groupMentorship->description,
                    'day' => $this->groupMentorship->day,
                    'start_time' => $this->groupMentorship->start_time,
                    'duration' => '60 minutes',
                    'no_of_sessions' => '4 sessions',
                    'min_participants' => $this->groupMentorship->min_participants,
                    'max_participants' => $this->groupMentorship->max_participants,
                    'available_slots' => $this->groupMentorship->available_slots,
                ];
            }),
            'mock_interview' => $this->when($this->service_type === 'Mock_Interview' && $this->mockInterview, function () {
                return [
                    'interview_type' => $this->mockInterview->interview_type,
                    'interview_level' => $this->mockInterview->interview_level,
                    'duration' => '60 minutes',
                    'no_of_sessions' => '1 session',
                ];
            }),
        ];

        return array_filter($data, function ($value) {
            return !is_null($value);
        });
    }
}
