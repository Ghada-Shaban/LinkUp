<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class GroupMentorshipResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'service_id' => $this->service_id,
            'title' => $this->groupMentorship ? $this->groupMentorship->title : null,
            'description' => $this->groupMentorship ? $this->groupMentorship->description : null,
            'day' => $this->groupMentorship ? $this->groupMentorship->day : null,
            'start_time' => $this->groupMentorship ? $this->groupMentorship->start_time : null,
            'price' => $this->price ? $this->price->price : null,
            'duration'=>'60 minutes',
            'no.of sessions'=>'4 sessions',
            'max_participants' => $this->groupMentorship->max_participants,
            'available_slots' => $this->groupMentorship->available_slots,

        ];
    }
}
