<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CoachResource extends JsonResource
{
    public function toArray($request)
    {
        // جلب عدد الجلسات المنتهية
        $completedSessions = $this->user->services->flatMap->sessions->where('status', 'Completed')->count();

        // جلب السعر من أول خدمة
        $service = $this->user->services->first();
        $price = $service && $service->prices->first() ? $service->prices->first()->price : 'N/A';

        // جلب المهارات
        $skills = $this->skills->pluck('skill')->toArray();
        $displaySkills = array_slice($skills, 0, 6); // أول 6 مهارات
        $moreSkillsCount = count($skills) > 6 ? count($skills) - 6 : 0; // عدد المهارات الزيادة

        // حساب سنين الخبرة
        $yearsOfExperience = $this->Years_Of_Experience + floor($this->Months_Of_Experience / 12);
        $experienceText = $yearsOfExperience > 0 ? "$yearsOfExperience+ years of experience" : "Less than a year of experience";

        return [
            'coach_id' => $this->User_ID,
            'name' => $this->user->full_name,
            'role' => $this->Title,
            'company' => $this->Company_or_School,
            'experience' => $experienceText, // سنين الخبرة
            'bio' => $this->Bio, // الـ Bio
            'completed_sessions' => $completedSessions, // عدد الجلسات المنتهية
            'reviews_count' => $this->reviews->count(), // عدد التقييمات
            'skills' => $displaySkills, // أول 6 مهارات
            'more_skills_count' => $moreSkillsCount > 0 ? "+$moreSkillsCount More" : null, // عدد المهارات الزيادة
            'price' => $price, // السعر
            'profile_picture' => $this->user->photo ?? 'https://via.placeholder.com/150', // صورة الكوتش
        ];
    }
}
