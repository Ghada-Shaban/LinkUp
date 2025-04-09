<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CoachResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            
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
            'reviews' => ReviewResource::collection($this->reviews),
        ];
    }
}
