<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MentorshipSessionResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'service_id' => $this->service_id,
            'session_type' => $this->mentorship->mentorshipSession ? $this->mentorship->mentorshipSession->session_type : null,
            'price' => $this->whenLoaded('price', function () {
    return $this->price->price ?? null;
}),
            'duration'=>'60 minutes',
            'no.of sessions'=>'1 session',
            
        ];
    }
}
