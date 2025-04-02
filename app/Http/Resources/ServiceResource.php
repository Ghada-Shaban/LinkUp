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
            'mentorship' => $this->when($this->service_type === 'Mentorship' && $this->mentorship, function () {
    $mentorshipData = [];

    if ($this->mentorship->mentorshipPlan) {
        $mentorshipData['mentorship_type'] = 'Mentorship plan';
        $mentorshipData['mentorship_plan'] = [
            'title' => $this->mentorship->mentorshipPlan->title,
            'duration' => '60 minutes',
            'no_of_sessions' => '4 sessions',
        ];
    }

    if ($this->mentorship->mentorshipSession) {
        $mentorshipData['mentorship_type'] = 'Mentorship session';
        $mentorshipData['mentorship_session'] = [
            'session_type' => $this->mentorship->mentorshipSession->session_type,
            'duration' => '60 minutes',
            'no_of_sessions' => '1 session',
        ];
    }

    return $mentorshipData ?: null;
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
