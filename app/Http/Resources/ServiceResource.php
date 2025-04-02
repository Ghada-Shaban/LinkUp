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
            'price' => $this->when($this->price, $this->price->price),
        ];

        // إضافة بيانات Mentorship إذا كان هذا هو نوع الخدمة
        if ($this->service_type === 'Mentorship') {
            // التحقق من وجود علاقة mentorship
            if ($this->mentorship) {
                // التحقق من نوع الإرشاد ووجود البيانات المرتبطة
                if ($this->mentorship->mentorship_type === 'Mentorship plan' && $this->mentorship->mentorshipPlan) {
                    $data['mentorship'] = [
                        'mentorship_plan' => [
                            'title' => $this->mentorship->mentorshipPlan->title,
                            'duration' => '60 minutes',
                            'no_of_sessions' => '4 sessions',
                        ]
                    ];
                } else if ($this->mentorship->mentorship_type === 'Mentorship session' && $this->mentorship->mentorshipSession) {
                    $data['mentorship'] = [
                        'mentorship_session' => [
                            'session_type' => $this->mentorship->mentorshipSession->session_type,
                            'duration' => '60 minutes',
                            'no_of_sessions' => '1 session',
                        ]
                    ];
                }
            }
        }

        // إضافة بيانات Group Mentorship إذا كان هذا هو نوع الخدمة
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
                    (($this->groupMentorship->max_participants ?? 5) - ($this->groupMentorship->current_participants ?? 0))
            ];
        }

        // إضافة بيانات Mock Interview إذا كان هذا هو نوع الخدمة
        if ($this->service_type === 'Mock_Interview' && $this->mockInterview) {
            $data['mock_interview'] = [
                'interview_type' => $this->mockInterview->interview_type,
                'interview_level' => $this->mockInterview->interview_level,
                'duration' => '60 minutes',
                'no_of_sessions' => '1 session',
            ];
        }

        // نحن لا نقوم بفلترة البيانات الأساسية مثل service_id و service_type
        // ولكن نقوم بفلترة البيانات المرتبطة بالعلاقات فقط
        return $data;
    }
}
