<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CoachResource extends JsonResource
{
    public function toArray($request)
    {
        // جلب عدد الجلسات المنتهية
        $completedSessions = $this->services->flatMap->sessions->where('status', 'Completed')->count();

        // جلب السعر من أول خدمة
        $service = $this->services->first();
        $price = $service && $service->price->first() ? $service->price->first()->price : 'N/A';

        // جلب المهارات
        $skills = $this->skills->pluck('skill')->toArray();
        $displaySkills = array_slice($skills, 0, 6); // أول 6 مهارات
        $moreSkillsCount = count($skills) > 6 ? count($skills) - 6 : 0; // عدد المهارات الزيادة

        // حساب سنين الخبرة
        $yearsOfExperience = $this->coach ? ($this->coach->Years_Of_Experience + floor($this->coach->Months_Of_Experience / 12)) : 0;
        $experienceText = $yearsOfExperience > 0 ? "$yearsOfExperience+ years of experience" : "Less than a year of experience";

        return [
            'coach_id' => $this->User_ID,
            'name' => $this->full_Name,
            'role' => $this->coach ? $this->coach->Title : 'N/A',
            'company' => $this->coach ? $this->coach->Company_or_School : 'N/A',
            'experience' => $experienceText, // سنين الخبرة
            'bio' => $this->coach ? $this->coach->Bio : 'N/A', // الـ Bio
            'completed_sessions' => $completedSessions, // عدد الجلسات المنتهية
            'reviews_count' => $this->reviewsAsCoach->count(), // عدد التقييمات
            'skills' => $displaySkills, // أول 6 مهارات
            'more_skills_count' => $moreSkillsCount > 0 ? "+$moreSkillsCount More" : null, // عدد المهارات الزيادة
            'price' => $price, // السعر
            'profile_picture' => $this->Photo ?? 'https://via.placeholder.com/150', // صورة الكوتش
        ];
    }
}
