<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MentorshipPlanResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'service_id' => $this->service_id,
            'title' => $this->mentorship->mentorshipPlan ? $this->mentorship->mentorshipPlan->title : null,
           'price' => $this->whenLoaded('price', function () {
    return $this->price->price ?? null;
}),
            'role'=> $this->mentorship->role,
            'career_phase' => $this->mentorship->career_phase,
            'duration'=>'60 minutes',
            'no.of sessions'=>'4 sessions',
            
        ];
    }
}
