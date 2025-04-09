<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CoachResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'user_id' => $this->User_ID,
            'full_name' => $this->user->Full_Name,
            'email' => $this->user->Email,
            'photo' => $this->user->Photo,
            'linkedin_link' => $this->user->Linkedin_Link,
            'title' => $this->Title,
            'company_or_school' => $this->Company_or_School,
            'bio' => $this->Bio,
            'years_of_experience' => $this->Years_Of_Experience,
            'months_of_experience' => $this->Months_Of_Experience,
            'skills' => $this->skills->pluck('Skill'),
            'languages' => $this->languages->pluck('Language'),
            'availability' => $this->availabilities->map(function ($availability) {
                return [
                    'day_of_week' => $availability->Day_Of_Week,
                    'start_time' => $availability->Start_Time,
                    'end_time' => $availability->End_Time,
                ];
            }),
            'reviews' => ReviewResource::collection($this->reviews),
        ];
    }
}
