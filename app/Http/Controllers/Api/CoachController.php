<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CoachResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CoachController extends Controller
{
    public function exploreCoaches(Request $request)
    {
        $currentUser = auth()->user();
        if ($currentUser->role_profile !== 'Trainee') {
            return response()->json(['message' => 'Unauthorized: Only Trainees can access this endpoint'], 403);
        }

        $search = $request->query('search', '');
        $perPage = $request->query('per_page', 50);
        $serviceType = $request->query('service_type', '');

        $coachesQuery = User::with(['coach', 'services.price', 'services.sessions', 'skills', 'reviewsAsCoach'])
            ->where('role_profile', 'Coach')
            ->whereHas('coach', function ($query) {
                $query->where('status', 'approved');
            })
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('full_name', 'like', "%{$search}%")
                      ->orWhereHas('coach', function ($q) use ($search) {
                          $q->where('Title', 'like', "%{$search}%");
                      })
                      ->orWhereHas('services', function ($q) use ($search) {
                          $q->where('service_type', 'like', "%{$search}%");
                      })
                      ->orWhereHas('skills', function ($q) use ($search) {
                          $q->where('skill', 'like', "%{$search}%");
                      });
                });
            });

        $testCoach = User::with(['coach', 'services.price', 'services.sessions', 'skills', 'reviewsAsCoach'])
            ->where('User_ID', 61)
            ->first();

        $coaches = $coachesQuery->paginate($perPage);
         return CoachResource::collection($coaches);
    }

    public function exploreServices(Request $request)
    {
        $search = $request->query('search', '');
        $perPage = $request->query('per_page', 50);
        $serviceType = $request->query('service_type', '');

        $coachesQuery = User::with(['coach', 'services.price', 'services.sessions', 'skills', 'reviewsAsCoach'])
            ->where('role_profile', 'Coach')
            ->whereHas('coach', function ($query) {
                $query->where('status', 'approved');
            })
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('full_name', 'like', "%{$search}%")
                      ->orWhereHas('coach', function ($q) use ($search) {
                          $q->where('Title', 'like', "%{$search}%");
                      })
                      ->orWhereHas('services', function ($q) use ($search) {
                          $q->where('service_type', 'like', "%{$search}%");
                      })
                      ->orWhereHas('skills', function ($q) use ($search) {
                          $q->where('skill', 'like', "%{$search}%");
                      });
                });
            })
            ->when($serviceType && $serviceType !== 'All', function ($query) use ($serviceType) {
                $query->whereHas('services', function ($q) use ($serviceType) {
                    if ($serviceType === 'Mentorship') {
                        $q->where('service_type', 'Mentorship');
                    } elseif ($serviceType === 'Mentorship session') {
                        $q->where('service_type', 'Mentorship')
                          ->whereHas('mentorships', function ($subQuery) {
                              $subQuery->where('mentorship_type', 'Mentorship session');
                          });
                    } elseif ($serviceType === 'Mentorship plan') {
                        $q->where('service_type', 'Mentorship')
                          ->whereHas('mentorships', function ($subQuery) {
                              $subQuery->where('mentorship_type', 'Mentorship plan');
                          });
                    } elseif (in_array($serviceType, ['Project Assessment', 'CV Review', 'LinkedIn Optimization'])) {
                        $q->where('service_type', 'Mentorship')
                          ->whereHas('mentorships', function ($subQuery) use ($serviceType) {
                              $subQuery->where('mentorship_type', 'Mentorship session')
                                       ->whereHas('mentorshipSessions', function ($subSubQuery) use ($serviceType) {
                                           $subSubQuery->where('session_type', $serviceType);
                                       });
                          });
                    } else {
                        $serviceTypeMap = [
                            'Mock interviews' => 'Mock_Interview',
                            'Group mentorship' => 'Group_Mentorship',
                        ];
                        $mappedServiceType = $serviceTypeMap[$serviceType] ?? $serviceType;
                        $q->where('service_type', $mappedServiceType);
                    }
                });
            });

        $coaches = $coachesQuery->paginate($perPage);
        return CoachResource::collection($coaches);
    }
   
}
