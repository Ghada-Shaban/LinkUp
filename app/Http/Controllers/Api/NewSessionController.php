<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\NewSession;
use App\Models\Service;
use App\Models\User;
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
                'service.mentorship.mentorshipPlan',
                'service.mentorship.mentorshipSession',
                'trainees',
                'mentorshipRequest.requestable'
            ])->where('coach_id', $user->User_ID)
              ->whereIn('status', $statuses);

            if ($timeCondition) {
                $query->where($timeCondition[0], $timeCondition[1], $timeCondition[2]);
            }

            $sessions = $query->get()->map(function ($session) {
                // جلب الـ trainee
                $trainee = $session->trainees;
                $traineeName = $trainee ? ($trainee->full_name ?? 'N/A') : 'N/A';
                if ($trainee) {
                    Log::info('Trainee found for session', [
                        'session_id' => $session->new_session_id,
                        'trainee_id' => $session->trainee_id,
                        'trainee_name' => $traineeName
                    ]);
                } else {
                    Log::warning('Trainee not found for session', [
                        'session_id' => $session->new_session_id,
                        'trainee_id' => $session->trainee_id
                    ]);
                }

                // جلب الـ service
                $service = $session->service;
                $sessionType = 'N/A';
                $serviceTitle = 'N/A';
                $currentParticipants = null;

                if ($service) {
                    Log::info('Service found for session', [
                        'session_id' => $session->new_session_id,
                        'service_id' => $session->service_id,
                        'service_type' => $service->service_type
                    ]);

                    if ($service->service_type === 'Mentorship') {
                        $mentorship = $service->mentorship;
                        if ($mentorship) {
                            Log::info('Mentorship found for service', [
                                'session_id' => $session->new_session_id,
                                'service_id' => $service->service_id,
                                'mentorship_type' => $mentorship->mentorship_type
                            ]);

                            if ($mentorship->mentorship_type === 'Mentorship session') {
                                $sessionType = 'mentorship sessions';
                                $mentorshipSession = $mentorship->mentorshipSession;
                                if ($mentorshipSession) {
                                    Log::info('Mentorship session found', [
                                        'session_id' => $session->new_session_id,
                                        'service_id' => $service->service_id,
                                        'session_type' => $mentorshipSession->session_type
                                    ]);
                                    $serviceTitle = $mentorshipSession->session_type;
                                } else {
                                    Log::warning('Mentorship session not found', [
                                        'session_id' => $session->new_session_id,
                                        'service_id' => $service->service_id
                                    ]);
                                    $serviceTitle = 'Mentorship Session';
                                }
                            } elseif ($mentorship->mentorship_type === 'Mentorship Plan') {
                                $sessionType = 'mentorship plan';
                                $mentorshipPlan = $mentorship->mentorshipPlan;
                                if ($mentorshipPlan) {
                                    Log::info('Mentorship plan found', [
                                        'session_id' => $session->new_session_id,
                                        'service_id' => $service->service_id,
                                        'plan_title' => $mentorshipPlan->title
                                    ]);
                                    $serviceTitle = $mentorshipPlan->title;
                                } else {
                                    Log::warning('Mentorship plan not found', [
                                        'session_id' => $session->new_session_id,
                                        'service_id' => $service->service_id
                                    ]);
                                    $serviceTitle = 'Mentorship Plan';
                                }
                            }
                        } else {
                            Log::warning('No mentorship found for service', [
                                'session_id' => $session->new_session_id,
                                'service_id' => $service->service_id
                            ]);
                            $sessionType = 'mentorship';
                            $serviceTitle = 'Mentorship';
                        }
                    } elseif ($service->service_type === 'Mock_Interview') {
                        $sessionType = 'mock interview';
                        $mockInterview = $service->mockInterview;
                        if ($mockInterview) {
                            Log::info('Mock Interview found', [
                                'session_id' => $session->new_session_id,
                                'service_id' => $service->service_id
                            ]);
                            $serviceTitle = $mockInterview->interview_type . ' (' . $mockInterview->interview_level . ')';
                        } else {
                            Log::warning('Mock Interview not found', [
                                'session_id' => $session->new_session_id,
                                'service_id' => $service->service_id
                            ]);
                            $serviceTitle = 'Mock Interview';
                        }
                    } elseif ($service->service_type === 'Group_Mentorship') {
                        $sessionType = 'group mentorship';
                        $groupMentorship = $service->groupMentorship;
                        if ($groupMentorship) {
                            Log::info('Group Mentorship found', [
                                'session_id' => $session->new_session_id,
                                'service_id' => $service->service_id
                            ]);
                            $serviceTitle = $groupMentorship->title;
                            $currentParticipants = $groupMentorship->current_participants;
                        } else {
                            Log::warning('Group Mentorship not found', [
                                'session_id' => $session->new_session_id,
                                'service_id' => $service->service_id
                            ]);
                            $serviceTitle = 'Group Mentorship';
                            $currentParticipants = 0;
                        }
                    } else {
                        $sessionType = strtolower(str_replace('_', ' ', $service->service_type));
                        $serviceTitle = $service->title ?? 'N/A';
                    }
                } else {
                    Log::warning('Service not found for session', [
                        'session_id' => $session->new_session_id,
                        'service_id' => $session->service_id
                    ]);
                }

                // تحويل التوقيت إلى EEST (UTC+3) إلا لو Mentorship Plan
                $isMentorshipPlan = $session->mentorshipRequest && $session->mentorshipRequest->requestable_type === \App\Models\MentorshipPlan::class;
                $dateTime = Carbon::parse($session->date_time);
                if (!$isMentorshipPlan) {
                    $dateTime->addHours(3);
                }

                Log::info('Session time processing', [
                    'session_id' => $session->new_session_id,
                    'is_mentorship_plan' => $isMentorshipPlan,
                    'original_time' => $session->date_time,
                    'displayed_time' => $dateTime->toDateTimeString(),
                ]);

                $endTime = $dateTime->copy()->addMinutes($session->duration);
                $date = $dateTime->format('d M Y');
                $timeRange = $dateTime->format('h:i A') . ' - ' . $endTime->format('h:i A');

                // تنسيق created_at و updated_at
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
                    'updated_at' => $updatedAt->toDateTimeString(),
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
                'service.mentorship.mentorshipPlan',
                'service.mentorship.mentorshipSession',
                'coach.user',
                'mentorshipRequest.requestable'
            ])->where('trainee_id', $user->User_ID)
              ->whereIn('status', $statuses);

            if ($timeCondition) {
                $query->where($timeCondition[0], $timeCondition[1], $timeCondition[2]);
            }

            $sessions = $query->get()->map(function ($session) {
                // جلب الـ coach
                $coach = $session->coach;
                $coachName = 'N/A';
                if ($coach && $coach->user) {
                    $coachName = $coach->user->full_name ?? 'N/A';
                    Log::info('Coach found for session', [
                        'session_id' => $session->new_session_id,
                        'coach_id' => $session->coach_id,
                        'coach_name' => $coachName
                    ]);
                } else {
                    Log::warning('Coach not found for session', [
                        'session_id' => $session->new_session_id,
                        'coach_id' => $session->coach_id
                    ]);
                }

                // جلب الـ service
                $service = $session->service;
                $sessionType = 'N/A';
                $serviceTitle = 'N/A';
                $currentParticipants = null;

                if ($service) {
                    Log::info('Service found for session', [
                        'session_id' => $session->new_session_id,
                        'service_id' => $session->service_id,
                        'service_type' => $service->service_type
                    ]);

                    if ($service->service_type === 'Mentorship') {
                        $mentorship = $service->mentorship;
                        if ($mentorship) {
                            Log::info('Mentorship found for service', [
                                'session_id' => $session->new_session_id,
                                'service_id' => $service->service_id,
                                'mentorship_type' => $mentorship->mentorship_type
                            ]);

                            if ($mentorship->mentorship_type === 'Mentorship session') {
                                $sessionType = 'mentorship sessions';
                                $mentorshipSession = $mentorship->mentorshipSession;
                                if ($mentorshipSession) {
                                    Log::info('Mentorship session found', [
                                        'session_id' => $session->new_session_id,
                                        'service_id' => $service->service_id,
                                        'session_type' => $mentorshipSession->session_type
                                    ]);
                                    $serviceTitle = $mentorshipSession->session_type;
                                } else {
                                    Log::warning('Mentorship session not found', [
                                        'session_id' => $session->new_session_id,
                                        'service_id' => $service->service_id
                                    ]);
                                    $serviceTitle = 'Mentorship Session';
                                }
                            } elseif ($mentorship->mentorship_type === 'Mentorship Plan') {
                                $sessionType = 'mentorship plan';
                                $mentorshipPlan = $session->mentorshipRequest && $session->mentorshipRequest->requestable
                                    ? $session->mentorshipRequest->requestable
                                    : $mentorship->mentorshipPlan;
                                if ($mentorshipPlan) {
                                    Log::info('Mentorship plan found', [
                                        'session_id' => $session->new_session_id,
                                        'service_id' => $service->service_id,
                                        'plan_title' => $mentorshipPlan->title
                                    ]);
                                    $serviceTitle = $mentorshipPlan->title;
                                } else {
                                    Log::warning('Mentorship plan not found', [
                                        'session_id' => $session->new_session_id,
                                        'service_id' => $service->service_id
                                    ]);
                                    $serviceTitle = 'Mentorship Plan';
                                }
                            }
                        } else {
                            Log::warning('No mentorship found for service', [
                                'session_id' => $session->new_session_id,
                                'service_id' => $service->service_id
                            ]);
                            $sessionType = 'mentorship';
                            $serviceTitle = 'Mentorship';
                        }
                    } elseif ($service->service_type === 'Mock_Interview') {
                        $sessionType = 'mock interview';
                        $mockInterview = $service->mockInterview;
                        if ($mockInterview) {
                            Log::info('Mock Interview found', [
                                'session_id' => $session->new_session_id,
                                'service_id' => $service->service_id
                            ]);
                            $serviceTitle = $mockInterview->interview_type . ' (' . $mockInterview->interview_level . ')';
                        } else {
                            Log::warning('Mock Interview not found', [
                                'session_id' => $session->new_session_id,
                                'service_id' => $service->service_id
                            ]);
                            $serviceTitle = 'Mock Interview';
                        }
                    } elseif ($service->service_type === 'Group_Mentorship') {
                        $sessionType = 'group mentorship';
                        $groupMentorship = $service->groupMentorship;
                        if ($groupMentorship) {
                            Log::info('Group Mentorship found', [
                                'session_id' => $session->new_session_id,
                                'service_id' => $service->service_id
                            ]);
                            $serviceTitle = $groupMentorship->title;
                            $currentParticipants = $groupMentorship->current_participants;
                        } else {
                            Log::warning('Group Mentorship not found', [
                                'session_id' => $session->new_session_id,
                                'service_id' => $session->service_id
                            ]);
                            $serviceTitle = 'Group Mentorship';
                            $currentParticipants = 0;
                        }
                    } else {
                        $sessionType = strtolower(str_replace('_', ' ', $service->service_type));
                        $serviceTitle = $service->title ?? 'N/A';
                    }
                } else {
                    Log::warning('Service not found for session', [
                        'session_id' => $session->new_session_id,
                        'service_id' => $session->service_id
                    ]);
                }

                // تحويل التوقيت إلى EEST (UTC+3) إلا لو Mentorship Plan
                $isMentorshipPlan = $session->mentorshipRequest && $session->mentorshipRequest->requestable_type === \App\Models\MentorshipPlan::class;
                $dateTime = Carbon::parse($session->date_time);
                if (!$isMentorshipPlan) {
                    $dateTime->addHours(3);
                }

                Log::info('Session time processing', [
                    'session_id' => $session->new_session_id,
                    'is_mentorship_plan' => $isMentorshipPlan,
                    'original_time' => $session->date_time,
                    'displayed_time' => $dateTime->toDateTimeString(),
                ]);

                $endTime = $dateTime->copy()->addMinutes($session->duration);
                $date = $dateTime->format('d M Y');
                $timeRange = $dateTime->format('h:i A') . ' - ' . $endTime->format('h:i A');

                // تنسيق created_at و updated_at
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
                    'updated_at' => $updatedAt->toDateTimeString(),
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

        return response()->json([
            'sessions' => $sessions
        ]);
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
