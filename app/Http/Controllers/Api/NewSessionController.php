<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\NewSession;
use App\Models\Service;
use App\Models\User;
use App\Models\MentorshipPlan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class NewSessionController extends Controller
{
    /**
     * عرض الجلسات بناءً على دور المستخدم ونوع الطلب (upcoming, pending, history)
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $type = $request->query('type', 'upcoming');

        $statuses = [];
        $timeCondition = null;

        if ($type === 'upcoming') {
            $statuses = ['Scheduled'];
            $timeCondition = ['date_time', '>=', now()];
        } elseif ($type === 'pending') {
            $statuses = ['pending'];
            $timeCondition = ['date_time', '>=', now()];
        } elseif ($type === 'history') {
            $statuses = ['completed', 'cancelled'];
            $timeCondition = null;
        } else {
            Log::warning('Invalid type parameter', [
                'user_id' => $user->User_ID,
                'type' => $type
            ]);
            return response()->json(['message' => 'Invalid type'], 400);
        }

        $sessions = collect();

        if ($user->role_profile === 'Coach') {
            $query = NewSession::with([
                'service' => function ($query) {
                    $query->whereNull('deleted_at')
                          ->with([
                              'mentorship' => function ($query) {
                                  $query->with(['mentorshipPlan', 'mentorshipSession']);
                              },
                              'mockInterview',
                              'groupMentorship'
                          ]);
                },
                'trainees',
                'mentorshipRequest.requestable'
            ])->where('coach_id', $user->User_ID)
              ->whereIn('status', $statuses);

            if ($timeCondition) {
                $query->where($timeCondition[0], $timeCondition[1], $timeCondition[2]);
            }

            $sessions = $query->get()->map(function ($session) {
                $traineeName = $session->trainees ? ($session->trainees->full_name ?? 'N/A') : 'N/A';
                Log::info('Trainee check', [
                    'session_id' => $session->new_session_id,
                    'trainee_id' => $session->trainee_id,
                    'trainee_name' => $traineeName,
                    'service_id' => $session->service_id
                ]);

                $service = $session->service;
                $sessionType = 'N/A';
                $serviceTitle = 'N/A';
                $currentParticipants = null;

                if ($service) {
                    Log::info('Service found', [
                        'session_id' => $session->new_session_id,
                        'service_id' => $session->service_id,
                        'service_type' => $service->service_type
                    ]);

                    if ($service->service_type === 'Mentorship') {
                        $mentorship = $service->mentorship;
                        if ($mentorship) {
                            Log::info('Mentorship found', [
                                'session_id' => $session->new_session_id,
                                'service_id' => $session->service_id,
                                'mentorship_type' => $mentorship->mentorship_type
                            ]);

                            if (strtolower($mentorship->mentorship_type) === 'mentorship session') {
                                $sessionType = 'mentorship sessions';
                                $mentorshipSession = $mentorship->mentorshipSession;
                                $serviceTitle = $mentorshipSession ? $mentorshipSession->session_type : 'Mentorship Session';
                                Log::info('Mentorship session', [
                                    'session_id' => $session->new_session_id,
                                    'service_id' => $session->service_id,
                                    'title' => $serviceTitle
                                ]);
                            } elseif (strtolower($mentorship->mentorship_type) === 'mentorship plan') {
                                $sessionType = 'mentorship plan';
                                $mentorshipPlan = $mentorship->mentorshipPlan;
                                $serviceTitle = $mentorshipPlan ? $mentorshipPlan->title : 'Mentorship Plan';
                                Log::info('Mentorship plan', [
                                    'session_id' => $session->new_session_id,
                                    'service_id' => $session->service_id,
                                    'title' => $serviceTitle,
                                    'mentorship_plan_exists' => !is_null($mentorshipPlan)
                                ]);
                            } else {
                                Log::warning('Unknown mentorship type', [
                                    'session_id' => $session->new_session_id,
                                    'service_id' => $session->service_id,
                                    'mentorship_type' => $mentorship->mentorship_type
                                ]);
                                $sessionType = 'mentorship';
                                $serviceTitle = 'Unknown Mentorship';
                            }
                        } else {
                            Log::warning('No mentorship found', [
                                'session_id' => $session->new_session_id,
                                'service_id' => $session->service_id
                            ]);
                            // Check mentorship_plans directly
                            $mentorshipPlan = MentorshipPlan::where('service_id', $session->service_id)->first();
                            if ($mentorshipPlan) {
                                $sessionType = 'mentorship plan';
                                $serviceTitle = $mentorshipPlan->title ?? 'Mentorship Plan';
                                Log::info('Mentorship plan found directly', [
                                    'session_id' => $session->new_session_id,
                                    'service_id' => $session->service_id,
                                    'title' => $serviceTitle
                                ]);
                            } else {
                                $sessionType = 'mentorship';
                                $serviceTitle = 'Mentorship';
                            }
                        }
                    } elseif ($service->service_type === 'Mock_Interview') {
                        $sessionType = 'mock interview';
                        $mockInterview = $service->mockInterview;
                        $serviceTitle = $mockInterview ? $mockInterview->interview_type . ' (' . $mockInterview->interview_level . ')' : 'Mock Interview';
                        Log::info('Mock Interview', [
                            'session_id' => $session->new_session_id,
                            'service_id' => $session->service_id,
                            'title' => $serviceTitle
                        ]);
                    } elseif ($service->service_type === 'Group_Mentorship') {
                        $sessionType = 'group mentorship';
                        $groupMentorship = $service->groupMentorship;
                        $serviceTitle = $groupMentorship ? $groupMentorship->title : 'Group Mentorship';
                        $currentParticipants = $groupMentorship ? $groupMentorship->current_participants : 0;
                        Log::info('Group Mentorship', [
                            'session_id' => $session->new_session_id,
                            'service_id' => $session->service_id,
                            'title' => $serviceTitle
                        ]);
                    } else {
                        $sessionType = strtolower(str_replace('_', ' ', $service->service_type));
                        $serviceTitle = $service->title ?? 'N/A';
                    }
                } else {
                    Log::warning('Service not found', [
                        'session_id' => $session->new_session_id,
                        'service_id' => $session->service_id
                    ]);
                }

                $isMentorshipPlan = $session->mentorshipRequest && $session->mentorshipRequest->requestable_type === \App\Models\MentorshipPlan::class;
                $dateTime = Carbon::parse($session->date_time);
                if (!$isMentorshipPlan) {
                    $dateTime->addHours(3);
                }

                Log::info('Session time processing', [
                    'session_id' => $session->new_session_id,
                    'is_mentorship_plan' => $isMentorshipPlan,
                    'original_time' => $session->date_time,
                    'displayed_time' => $dateTime->toDateTimeString()
                ]);

                $endTime = $dateTime->copy()->addMinutes($session->duration);
                $date = $dateTime->format('d M Y');
                $timeRange = $dateTime->format('h:i A') . ' - ' . $endTime->format('h:i A');

                $createdAt = Carbon::parse($session->created_at);
                $updatedAt = Carbon::parse($session->updated_at);
                if ($createdAt->format('Y-m-d') === '2025-05-29') {
                    $createdAt->subDay();
                }
                if ($updatedAt->format('Y-m-d') === '2025-05-29') {
                    $updatedAt->subDay();
                }

                $sessionData = [
                    'new_session_id' => $session->new_session_id,
                    'session_type' => $sessionType,
                    'title' => $serviceTitle,
                    'date' => $date,
                    'time_range' => $timeRange,
                    'status' => $session->status,
                    'meeting_link' => $session->meeting_link,
                    'trainee_name' => $traineeName,
                    'created_at' => $createdAt->toDateTimeString(),
                    'updated_at' => $updatedAt->toDateTimeString()
                ];

                if ($service && $service->service_type === 'Group_Mentorship') {
                    $sessionData['current_participants'] = $currentParticipants;
                }

                return $sessionData;
            });

            Log::info("Fetching $type sessions for Coach", [
                'user_id' => $user->User_ID,
                'sessions_count' => $sessions->count(),
                'sessions' => $sessions->toArray()
            ]);
        } elseif ($user->role_profile === 'Trainee') {
            $query = NewSession::with([
                'service' => function ($query) {
                    $query->whereNull('deleted_at')
                          ->with([
                              'mentorship' => function ($query) {
                                  $query->with(['mentorshipPlan', 'mentorshipSession']);
                              },
                              'mockInterview',
                              'groupMentorship'
                          ]);
                },
                'coach.user',
                'mentorshipRequest.requestable'
            ])->where('trainee_id', $user->User_ID)
              ->whereIn('status', $statuses);

            if ($timeCondition) {
                $query->where($timeCondition[0], $timeCondition[1], $timeCondition[2]);
            }

            $sessions = $query->get()->map(function ($session) {
                $coachName = $session->coach && $session->coach->user ? $session->coach->user->full_name : 'N/A';
                Log::info('Coach check', [
                    'session_id' => $session->new_session_id,
                    'coach_id' => $session->coach_id,
                    'coach_name' => $coachName,
                    'service_id' => $session->service_id
                ]);

                $service = $session->service;
                $sessionType = 'N/A';
                $serviceTitle = 'N/A';
                $currentParticipants = null;

                if ($service) {
                    Log::info('Service found', [
                        'session_id' => $session->new_session_id,
                        'service_id' => $session->service_id,
                        'service_type' => $service->service_type
                    ]);

                    if ($service->service_type === 'Mentorship') {
                        $mentorship = $service->mentorship;
                        if ($mentorship) {
                            Log::info('Mentorship found', [
                                'session_id' => $session->new_session_id,
                                'service_id' => $session->service_id,
                                'mentorship_type' => $mentorship->mentorship_type
                            ]);

                            if (strtolower($mentorship->mentorship_type) === 'mentorship session') {
                                $sessionType = 'mentorship sessions';
                                $mentorshipSession = $mentorship->mentorshipSession;
                                $serviceTitle = $mentorshipSession ? $mentorshipSession->session_type : 'Mentorship Session';
                                Log::info('Mentorship session', [
                                    'session_id' => $session->new_session_id,
                                    'service_id' => $session->service_id,
                                    'title' => $serviceTitle
                                ]);
                            } elseif (strtolower($mentorship->mentorship_type) === 'mentorship plan') {
                                $sessionType = 'mentorship plan';
                                $mentorshipPlan = $session->mentorshipRequest && $session->mentorshipRequest->requestable
                                    ? $session->mentorshipRequest->requestable
                                    : $mentorship->mentorshipPlan;
                                $serviceTitle = $mentorshipPlan ? $mentorshipPlan->title : 'Mentorship Plan';
                                Log::info('Mentorship plan', [
                                    'session_id' => $session->new_session_id,
                                    'service_id' => $session->service_id,
                                    'title' => $serviceTitle,
                                    'mentorship_plan_exists' => !is_null($mentorshipPlan)
                                ]);
                            } else {
                                Log::warning('Unknown mentorship type', [
                                    'session_id' => $session->new_session_id,
                                    'service_id' => $session->service_id,
                                    'mentorship_type' => $mentorship->mentorship_type
                                ]);
                                $sessionType = 'mentorship';
                                $serviceTitle = 'Unknown Mentorship';
                            }
                        } else {
                            Log::warning('No mentorship found', [
                                'session_id' => $session->new_session_id,
                                'service_id' => $session->service_id
                            ]);
                            // Check mentorship_plans directly
                            $mentorshipPlan = MentorshipPlan::where('service_id', $session->service_id)->first();
                            if ($mentorshipPlan) {
                                $sessionType = 'mentorship plan';
                                $serviceTitle = $mentorshipPlan->title ?? 'Mentorship Plan';
                                Log::info('Mentorship plan found directly', [
                                    'session_id' => $session->new_session_id,
                                    'service_id' => $session->service_id,
                                    'title' => $serviceTitle
                                ]);
                            } else {
                                $sessionType = 'mentorship';
                                $serviceTitle = 'Mentorship';
                            }
                        }
                    } elseif ($service->service_type === 'Mock_Interview') {
                        $sessionType = 'mock interview';
                        $mockInterview = $service->mockInterview;
                        $serviceTitle = $mockInterview ? $mockInterview->interview_type . ' (' . $mockInterview->interview_level . ')' : 'Mock Interview';
                        Log::info('Mock Interview', [
                            'session_id' => $session->new_session_id,
                            'service_id' => $session->service_id,
                            'title' => $serviceTitle
                        ]);
                    } elseif ($service->service_type === 'Group_Mentorship') {
                        $sessionType = 'group mentorship';
                        $groupMentorship = $service->groupMentorship;
                        $serviceTitle = $groupMentorship ? $groupMentorship->title : 'Group Mentorship';
                        $currentParticipants = $groupMentorship ? $groupMentorship->current_participants : 0;
                        Log::info('Group Mentorship', [
                            'session_id' => $session->new_session_id,
                            'service_id' => $session->service_id,
                            'title' => $serviceTitle
                        ]);
                    } else {
                        $sessionType = strtolower(str_replace('_', ' ', $service->service_type));
                        $serviceTitle = $service->title ?? 'N/A';
                    }
                } else {
                    Log::warning('Service not found', [
                        'session_id' => $session->new_session_id,
                        'service_id' => $session->service_id
                    ]);
                }

                $isMentorshipPlan = $session->mentorshipRequest && $session->mentorshipRequest->requestable_type === \App\Models\MentorshipPlan::class;
                $dateTime = Carbon::parse($session->date_time);
                if (!$isMentorshipPlan) {
                    $dateTime->addHours(3);
                }

                Log::info('Session time processing', [
                    'session_id' => $session->new_session_id,
                    'is_mentorship_plan' => $isMentorshipPlan,
                    'original_time' => $session->date_time,
                    'displayed_time' => $dateTime->toDateTimeString()
                ]);

                $endTime = $dateTime->copy()->addMinutes($session->duration);
                $date = $dateTime->format('d M Y');
                $timeRange = $dateTime->format('h:i A') . ' - ' . $endTime->format('h:i A');

                $createdAt = Carbon::parse($session->created_at);
                $updatedAt = Carbon::parse($session->updated_at);
                if ($createdAt->format('Y-m-d') === '2025-05-29') {
                    $createdAt->subDay();
                }
                if ($updatedAt->format('Y-m-d') === '2025-05-29') {
                    $updatedAt->subDay();
                }

                $sessionData = [
                    'new_session_id' => $session->new_session_id,
                    'session_type' => $sessionType,
                    'title' => $serviceTitle,
                    'date' => $date,
                    'time_range' => $timeRange,
                    'status' => $session->status,
                    'meeting_link' => $session->meeting_link,
                    'coach_name' => $coachName,
                    'created_at' => $createdAt->toDateTimeString(),
                    'updated_at' => $updatedAt->toDateTimeString()
                ];

                if ($service && $service->service_type === 'Group_Mentorship') {
                    $sessionData['current_participants'] = $currentParticipants;
                }

                return $sessionData;
            });

            Log::info("Fetching $type sessions for Trainee", [
                'user_id' => $user->User_ID,
                'sessions_count' => $sessions->count(),
                'sessions' => $sessions->toArray()
            ]);
        } else {
            Log::warning('Unauthorized access to sessions', [
                'user_id' => $user->User_ID,
                'role' => $user->role_profile
            ]);
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return response()->json(['sessions' => $sessions]);
    }

    /**
     * إتمام جلسة من الكوتش أو الترايني
     */
    public function completeSession($sessionId)
    {
        $user = Auth::user();
        $session = NewSession::findOrFail($sessionId);

        $isAuthorized = $user->role_profile === 'Coach' && $session->coach_id == $user->User_ID ||
                        $user->role_profile === 'Trainee' && $session->trainee_id == $user->User_ID;

        if (!$isAuthorized) {
            Log::warning('Unauthorized attempt to complete session', [
                'user_id' => $user->User_ID,
                'session_id' => $sessionId
            ]);
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($session->status !== 'Scheduled') {
            Log::warning('Session cannot be completed', [
                'session_id' => $sessionId,
                'status' => $session->status
            ]);
            return response()->json(['message' => 'Session cannot be completed'], 400);
        }

        $sessionEndTime = Carbon::parse($session->date_time)->addMinutes($session->duration);
        if (Carbon::now()->lt($sessionEndTime)) {
            Log::warning('Session cannot be completed yet', [
                'session_id' => $sessionId,
                'end_time' => $sessionEndTime
            ]);
            return response()->json(['message' => 'Session cannot be completed yet.'], 400);
        }

        $session->status = 'completed';
        $session->save();

        Log::info('Session marked as completed', ['session_id' => $sessionId]);

        return response()->json([
            'message' => 'Session marked as completed successfully!',
            'session' => $session
        ]);
    }

    /**
     * إلغاء جلسة من الكوتش أو الترايني
     */
    public function cancelSession($sessionId)
    {
        $user = Auth::user();
        $session = NewSession::findOrFail($sessionId);

        $isAuthorized = $user->role_profile === 'Coach' && $session->coach_id == $user->User_ID ||
                        $user->role_profile === 'Trainee' && $session->trainee_id == $user->User_ID;

        if (!$isAuthorized) {
            Log::warning('Unauthorized attempt to cancel session', [
                'user_id' => $user->User_ID,
                'session_id' => $sessionId
            ]);
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!in_array($session->status, ['Scheduled', 'pending'])) {
            Log::warning('Session cannot be cancelled', [
                'session_id' => $sessionId,
                'status' => $session->status
            ]);
            return response()->json(['message' => 'Session cannot be cancelled'], 400);
        }

        $session->status = 'cancelled';
        $session->save();

        Log::info('Session cancelled', ['session_id' => $sessionId]);

        return response()->json([
            'message' => 'Session cancelled successfully!',
            'session' => $session
        ]);
    }

    /**
     * تحديث رابط الاجتماع لجلسة معينة (خاص بالكوتش فقط)
     */
    public function updateMeetingLink(Request $request, $sessionId)
    {
        $request->validate(['meeting_link' => 'required|url']);

        $user = Auth::user();
        $session = NewSession::findOrFail($sessionId);

        if ($session->coach_id != $user->User_ID) {
            Log::warning('Unauthorized attempt to update meeting link', [
                'user_id' => $user->User_ID,
                'session_id' => $sessionId
            ]);
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $session->meeting_link = $request->meeting_link;
        $session->save();

        Log::info('Meeting link updated', [
            'session_id' => $sessionId,
            'meeting_link' => $session->meeting_link
        ]);

        return response()->json([
            'message' => 'Meeting link updated successfully!',
            'meeting_link' => $session->meeting_link
        ]);
    }
}
