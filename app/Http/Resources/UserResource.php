<?php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            
            'full_name' => $this->Full_Name,
            'email' => $this->Email,
            'photo' => $this->Photo ? url("storage/{$this->Photo}/{$this->User_ID}") : null,
            'linkedin_link' => $this->Linkedin_Link ? "{$this->Linkedin_Link}?user_id={$this->User_ID}" : null,
            
        ];
    }
}
