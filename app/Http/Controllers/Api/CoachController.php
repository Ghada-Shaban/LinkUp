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
     * Fetch coaches with their details for the Explore Coaches page (All coaches)
     */
    public function exploreCoaches(Request $request)
    {
        // جلب المستخدم المسجل حاليًا
        $currentUser = auth()->user();

        // التحقق إن المستخدم هو Trainee
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

        Log::info('Fetching coaches for Explore Coaches page', [
            'search' => $search,
            'service_type' => $serviceType,
            'coaches_count' => $coaches->total(),
            'trainee_id' => $currentUser->User_ID,
            'coaches_ids' => $coaches->pluck('User_ID')->toArray(),
            'test_coach' => $testCoach ? $testCoach->toArray() : 'Not found',
            'query_debug' => $coachesQuery->toSql(),
            'bindings' => $coachesQuery->getBindings(),
        ]);

        return CoachResource::collection($coaches);
    }

    /**
     * Fetch coaches filtered by service type (No authentication required)
     */
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

        Log::info('Fetching coaches for Explore Services page', [
            'search' => $search,
            'service_type' => $serviceType,
            'coaches_count' => $coaches->total(),
            'query_debug' => $coachesQuery->toSql(),
            'bindings' => $coachesQuery->getBindings(),
        ]);

        return CoachResource::collection($coaches);
    }
    public function submitPerformanceReport(Request $request, $sessionId)
{
    $coach = auth()->user();
    $session = NewSession::findOrFail($sessionId);

    if ($session->coach_id !== $coach->User_ID || !$session->isCompleted()) {
        return response()->json(['message' => 'Unauthorized or session not completed'], 403);
    }

    $request->validate([
        'overall_rating' => 'required|integer|min:1|max:5',
        'strengths' => 'required|string|max:1000',
        'weaknesses' => 'required|string|max:1000',
        'comments' => 'nullable|string|max:1000', 
    ]);

    $report = new PerformanceReport();
    $report->session_id = $sessionId;
    $report->coach_id = $coach->User_ID;
    $report->trainee_id = $session->trainee_id;
    $report->overall_rating = $request->overall_rating;
    $report->strengths = $request->strengths;
    $report->weaknesses = $request->weaknesses;
    $report->comments = $request->comments ?? null; 
    $report->save();

    return response()->json(['message' => 'Performance report submitted successfully'], 200);
}
}
