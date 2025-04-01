<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MockInterviewResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'service_id' => $this->service_id,
            'interview_type' => $this->mockInterview ? $this->mockInterview->interview_type : null,
            'interview_level' => $this->mockInterview ? $this->mockInterview->interview_level : null,
            'price' => $this->whenLoaded('price', function () {
    return $this->price->price ?? null;
}),
            'duration'=>'60 minutes',
            'no.of sessions'=>'1 session',
            
        ];
    }
}
