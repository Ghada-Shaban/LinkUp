<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'trainee_id' => $this->trainee_id,
            'coach_id' => $this->coach_id,
            'rating' => $this->rating,
            'comment' => $this->comment,
            
            'trainee' => [
                'full_name' => $this->trainee->user->full_name,
                'photo' => $this->trainee->user->photo,
                'current_role' => $this->trainee->Current_Role,
            ],
        ];
    }
}
