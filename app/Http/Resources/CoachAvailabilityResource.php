<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CoachAvailabilityResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request)
    {
       return [

            'user_id' => $this->User_ID,
            'day_of_week' => $this->Day_Of_Week,
            'start_time' => $this->Start_Time,
            'end_time' => $this->End_Time,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
