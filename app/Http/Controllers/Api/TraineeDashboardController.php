<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NewSession;
use App\Models\User;
use App\Models\MentorshipRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class TraineeDashboardController extends Controller
{
    public function getTraineeDashboardStats(Request $request)
    {
        $authTrainee = Auth::user();
        if (!$authTrainee || $authTrainee->role_profile !== 'Trainee') {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        try {
            // 1. Completed Sessions Count
            $completedSessionsCount = NewSession::where('status', NewSession::STATUS_COMPLETED)
                ->where('trainee_id', $authTrainee->User_ID)
                ->count();

            // 2. Total Learning Time (Sum of durations of completed sessions in hours)
            $completedSessions = NewSession::where('status', NewSession::STATUS_COMPLETED)
                ->where('trainee_id', $authTrainee->User_ID)
                ->get();
            $totalLearningTime = $completedSessions->sum('duration') / 60; // Convert minutes to hours

            // 3. Pending Session Requests Count
            $pendingSessionRequests = MentorshipRequest::where('status', 'pending')
                ->where('trainee_id', $authTrainee->User_ID)
                ->count();

            // 4. Top 5 Coaches based on average rating
            $topCoaches = User::with(['coach', 'services.sessions', 'reviewsAsCoach'])
                ->where('role_profile', 'Coach')
                ->whereHas('coach', function ($query) {
                    $query->where('status', 'approved');
                })
                ->whereHas('reviewsAsCoach') // Ensure the coach has reviews
                ->withCount(['reviewsAsCoach as average_rating' => function ($query) {
                    $query->select(\DB::raw('avg(rating)'));
                }])
                ->orderByDesc('average_rating')
                ->take(5)
                ->get()
                ->map(function ($coach) {
                    $completedSessionsCount = $coach->services
                        ->flatMap->sessions
                        ->where('status', NewSession::STATUS_COMPLETED)
                        ->count();

                    return [
                        'coach_id' => $coach->User_ID,
                        'name' => $coach->Full_Name,
                        'title' => $coach->coach->Title ?? 'N/A',
                        'average_rating' => round($coach->average_rating, 2),
                        'completed_sessions' => $completedSessionsCount,
                    ];
                });

            // 5. Upcoming Sessions (Top 5 Scheduled Sessions for the Trainee)
            $upcomingSessions = NewSession::where('status', 'Scheduled')
                ->where('trainee_id', $authTrainee->User_ID)
                ->where('date_time', '>=', Carbon::now())
                ->with(['mentorshipRequest', 'service'])
                ->orderBy('date_time', 'asc')
                ->take(5)
                ->get()
                ->map(function ($session) {
                    $sessionType = null;
                    if ($session->mentorship_request_id) {
                        $mentorshipRequest = $session->mentorshipRequest;
                        if ($mentorshipRequest) {
                            if ($mentorshipRequest->requestable_type === 'App\\Models\\MentorshipPlan') {
                                $sessionType = 'Mentorship Plan';
                            } elseif ($mentorshipRequest->requestable_type === 'App\\Models\\GroupMentorship') {
                                $sessionType = 'Group Mentorship';
                            }
                        }
                    }

                    $coach = User::where('User_ID', $session->coach_id)
                        ->where('role_profile', 'Coach')
                        ->first();

                    return [
                        'session_id' => $session->new_session_id,
                        'coach_name' => $coach->Full_Name ?? 'Unknown Coach',
                        'date_time' => Carbon::parse($session->date_time)->setTimezone('Africa/Cairo')->format('Y-m-d H:i:s'),
                        'duration' => $session->duration,
                        'service_type' => $session->service->service_type ?? 'Unknown Service',
                        'session_type' => $sessionType,
                    ];
                });

            // Return the response without pending_mentorship_requests
            return response()->json([
                'completed_sessions' => $completedSessionsCount,
                'total_learning_time' => round($totalLearningTime, 2),
                'pending_session_requests' => $pendingSessionRequests,
                'top_coaches' => $topCoaches,
                'upcoming_sessions' => $upcomingSessions,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve trainee dashboard stats', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'message' => 'Failed to retrieve trainee dashboard stats',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
