<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TraineeResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'full_name' => $this->trainee->user->full_name,
            'photo' => $this->trainee->user->photo,
            'email' => $this->Email,
            'linkedin_link' => $this->trainee->user->Linkedin_Link ,
            'education_level' => $this->Education_Level,
            'institution_or_school' => $this->Institution_Or_School,
            'field_of_study' => $this->Field_Of_Study,
            'current_role' => $this->Current_Role,
            'story' => $this->Story,
            'years_of_professional_experience' => $this->Years_Of_Professional_Experience,
            'preferred_languages' => $this->preferredLanguages->pluck('Language'),
            'areas_of_interest' => $this->areasOfInterest->pluck('Area_Of_Interest'),
            
        ];
    }
}
