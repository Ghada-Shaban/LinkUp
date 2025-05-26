<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CoachResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CoachController extends Controller
{
    /**
     * Fetch coaches with their details for the Explore Coaches page
     */
    public function exploreCoaches(Request $request)
    {
        // جلب المستخدم المسجل حاليًا
        $currentUser = auth()->user();

        // التحقق إن المستخدم هو Trainee
        if ($currentUser->role_profile !== 'Trainee') {
            return response()->json(['message' => 'Unauthorized: Only Trainees can access this endpoint'], 403);
        }

        $search = $request->query('search', ''); // Search query for multiple fields
        $perPage = $request->query('per_page', 50); // زوّدنا per_page لـ 50 عشان نضمن نجيب كل الـ coaches
        $serviceType = $request->query('service_type', ''); // جلب نوع الـ Service من الـ Request

        $coachesQuery = User::with(['coach', 'services.price', 'services.sessions', 'skills', 'reviewsAsCoach'])
            ->where('role_profile', 'Coach') // Only fetch users with role 'Coach'
            ->whereHas('coach', function ($query) {
                $query->where('status', 'approved'); // Only fetch coaches with status 'approved'
            })
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    // السيرش في full_name (من جدول users)
                    $q->where('full_name', 'like', "%{$search}%")
                      // السيرش في Title (من جدول coaches)
                      ->orWhereHas('coach', function ($q) use ($search) {
                          $q->where('Title', 'like', "%{$search}%");
                      })
                      // السيرش في service_type (من جدول services)
                      ->orWhereHas('services', function ($q) use ($search) {
                          $q->where('service_type', 'like', "%{$search}%");
                      })
                      // السيرش في skill (من جدول coach_skills)
                      ->orWhereHas('skills', function ($q) use ($search) {
                          $q->where('skill', 'like', "%{$search}%");
                      });
                });
            })
            ->when($serviceType && $serviceType !== 'All', function ($query) use ($serviceType) {
                $query->whereHas('services', function ($q) use ($serviceType) {
                    if ($serviceType === 'Mentorship') {
                        // جلب الكوتشز اللي بيقدموا Mentorship (سواء Mentorship session أو Mentorship plan)
                        $q->where('service_type', 'Mentorship');
                    } elseif ($serviceType === 'Mentorship session') {
                        // جلب الكوتشز اللي بيقدموا Mentorship session
                        $q->where('service_type', 'Mentorship')
                          ->whereHas('mentorships', function ($subQuery) {
                              $subQuery->where('mentorship_type', 'Mentorship session');
                          });
                    } elseif ($serviceType === 'Mentorship plan') {
                        // جلب الكوتشز اللي بيقدموا Mentorship plan
                        $q->where('service_type', 'Mentorship')
                          ->whereHas('mentorships', function ($subQuery) {
                              $subQuery->where('mentorship_type', 'Mentorship plan');
                          });
                    } elseif (in_array($serviceType, ['Project Assessment', 'CV Review', 'LinkedIn Optimization'])) {
                        // جلب الكوتشز اللي بيقدموا Mentorship session مع الـ sub-type المحدد
                        $q->where('service_type', 'Mentorship')
                          ->whereHas('mentorships', function ($subQuery) use ($serviceType) {
                              $subQuery->where('mentorship_type', 'Mentorship session')
                                       ->whereHas('mentorshipSessions', function ($subSubQuery) use ($serviceType) {
                                           $subSubQuery->where('sub_type', $serviceType);
                                       });
                          });
                    } else {
                        // جلب الكوتشز اللي بيقدموا الـ Service Type المحدد مباشرة (Group Mentorship أو Mock Interview)
                        $q->where('service_type', $serviceType);
                    }
                });
            });

        // جربة لجلب الـ coach الجديد مباشرة (استبدلي 61 بـ User_ID بتاع الـ coach)
        $testCoach = User::with(['coach', 'services.price', 'services.sessions', 'skills', 'reviewsAsCoach'])
            ->where('User_ID', 61) // استبدلي 61 بـ User_ID الجديد
            ->first();

        $coaches = $coachesQuery->paginate($perPage);

        Log::info('Fetching coaches for Explore Coaches page', [
            'search' => $search,
            'service_type' => $serviceType,
            'coaches_count' => $coaches->total(),
            'trainee_id' => $currentUser->User_ID,
            'coaches_ids' => $coaches->pluck('User_ID')->toArray(),
            'test_coach' => $testCoach ? $testCoach->toArray() : 'Not found',
        ]);

        return CoachResource::collection($coaches);
    }
}
