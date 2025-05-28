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
            $timeCondition = ['date_time', '>=', Carbon::now()];
        } else if ($type === 'pending') {
            $statuses = ['Pending'];
            $timeCondition = ['date_time', '>=', Carbon::now()];
        } else if ($type === 'history') {
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
            $query = NewSession::query()->with(['service', 'trainees'])
                ->where('coach_id', $user->User_ID)
                ->whereIn('status', $statuses);

            if ($timeCondition) {
                $query->where($timeCondition[0], $timeCondition[1], $timeCondition[2]->copy()->addHours(3)); // UTC to EEST
            }

            $sessions = $query->get()->map(function($session) {
                // جلب الـ trainee من العلاقة
                $trainee = $session->trainees; // العلاقة هترجّع كائن User أو null
                $traineeName = 'N/A';
                if ($trainee) {
                    $traineeName = $trainee->full_name ?? 'N/A';
                    Log::info('Trainee found for session', [
                        'session_id' => $session->new_session_id,
                        'trainee_id' => $session->trainee_id,
                        'trainee_name' => $traineeName,
                        'trainee_data' => $trainee->toArray()
                    ]);
                } else {
                    Log::warning('Trainee not found for session', [
                        'session_id' => $session->new_session_id,
                        'trainee_id' => $session->trainee_id
                    ]);
                }
                
                // جلب الـ service من العلاقة
                $service = $session->service;
                $sessionType = 'N/A'; // سيتم استخدامه لـ service_type
                $serviceTitle = 'N/A'; // حقل جديد للـ title
                $currentParticipants = null; // لإضافة عدد المشاركين الحاليين في GroupMentorship

                if ($service) {
                    Log::info('Service found for session', [
                        'session_id' => $session->new_session_id,
                        'service_id' => $session->service_id,
                        'service' => $service->toArray()
                    ]);
                    
                    // تحديد الـ session_type بناءً على الـ service_type
                    if ($service->service_type === 'Mentorship') {
                        $sessionType = 'mentorship sessions';
                    } else {
                        $sessionType = strtolower(str_replace('_', ' ', $service->service_type)); // مثل "group mentorship"
                    }

                    // تحديد الـ service_title بناءً على الجداول الفرعية
                    if ($service->service_type === 'Mentorship') {
                        $mentorship = $service->mentorship;
                        if ($mentorship) {
                            Log::info('Mentorship found for service', [
                                'service_id' => $service->service_id,
                                'mentorship' => $mentorship->toArray()
                            ]);
                            if ($mentorship->mentorship_type === 'Mentorship session') {
                                // تحقق من جدول mentorship_sessions
                                $mentorshipSession = $service->mentorshipSession;
                                if ($mentorshipSession) {
                                    Log::info('Mentorship session found for service', [
                                        'service_id' => $service->service_id,
                                        'mentorship_session' => $mentorshipSession->toArray()
                                    ]);
                                    $serviceTitle = $mentorshipSession->session_type; // مثل "CV Review"
                                } else {
                                    Log::warning('Mentorship session not found for service', [
                                        'service_id' => $service->service_id
                                    ]);
                                    $serviceTitle = 'Mentorship Session';
                                }
                            } elseif ($mentorship->mentorship_type === 'Mentorship plan') {
                                $serviceTitle = 'Mentorship Plan';
                            }
                        } else {
                            Log::warning('No mentorship found for service', [
                                'service_id' => $service->service_id
                            ]);
                        }
                    } elseif ($service->service_type === 'Mock_Interview') {
                        $mockInterview = $service->mockInterview;
                        if ($mockInterview) {
                            Log::info('Mock Interview found for service', [
                                'service_id' => $service->service_id,
                                'mock_interview' => $mockInterview->toArray()
                            ]);
                            $serviceTitle = $mockInterview->interview_type . ' (' . $mockInterview->interview_level . ')';
                        } else {
                            Log::warning('Mock Interview not found for service', [
                                'service_id' => $service->service_id
                            ]);
                            $serviceTitle = 'Mock Interview';
                        }
                    } elseif ($service->service_type === 'Group_Mentorship') {
                        $groupMentorship = $service->groupMentorship;
                        if ($groupMentorship) {
                            Log::info('Group Mentorship found for service', [
                                'service_id' => $service->service_id,
                                'group_mentorship' => $groupMentorship->toArray()
                            ]);
                            $serviceTitle = $groupMentorship->title; // مثل "Weekly Coding Bootcamp3"
                            $currentParticipants = $groupMentorship->current_participants; // عدد المشاركين الحاليين
                        } else {
                            Log::warning('Group Mentorship not found for service', [
                                'service_id' => $service->service_id
                            ]);
                            $serviceTitle = 'Group Mentorship';
                            $currentParticipants = 0;
                        }
                    } else {
                        $serviceTitle = $service->title ?? 'N/A';
                    }
                } else {
                    Log::warning('Service not found for session', [
                        'session_id' => $session->new_session_id,
                        'service_id' => $session->service_id
                    ]);
                }

                // حساب وقت النهاية بناءً على المدة مع تحويل إلى EEST
                $startTime = Carbon::parse($session->date_time)->addHours(3); // UTC to EEST
                $endTime = $startTime->copy()->addMinutes($session->duration);
                
                // تنسيق التاريخ والوقت بالشكل المطلوب
                $date = $startTime->format('D, M d');
                $startTimeFormatted = $startTime->format('h:i A');
                $endTimeFormatted = $endTime->format('h:i A');
                $timeRange = "$startTimeFormatted - $endTimeFormatted";

                $sessionData = [
                    'new_session_id' => $session->new_session_id,
                    'session_type' => $sessionType,
                    'title' => $serviceTitle,
                    'date' => $date,
                    'time_range' => $timeRange,
                    'status' => $session->status,
                    'meeting_link' => $session->meeting_link,
                    'trainee_name' => $traineeName,
                ];

                // إضافة current_participants إذا كان الـ session من نوع GroupMentorship
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
            $query = NewSession::query()->with(['service', 'coach.user'])
                ->where('trainee_id', $user->User_ID)
                ->whereIn('status', $statuses);

            if ($timeCondition) {
                $query->where($timeCondition[0], $timeCondition[1], $timeCondition[2]->copy()->addHours(3)); // UTC to EEST
            }

            $sessions = $query->get()->map(function ($session) {
                // جلب الـ coach من العلاقة
                $coach = $session->coach;
                $coachName = 'N/A';
                if ($coach) {
                    $coachUser = $coach->user;
                    if ($coachUser) {
                        $coachName = $coachUser->full_name ?? 'N/A';
                        Log::info('Coach found for session', [
                            'session_id' => $session->new_session_id,
                            'coach_id' => $session->coach_id,
                            'coach_name' => $coachName,
                            'user_data' => $coachUser->toArray()
                        ]);
                    } else {
                        Log::warning('Coach user not found in users table for session', [
                            'session_id' => $session->new_session_id,
                            'coach_id' => $session->coach_id
                        ]);
                    }
                } else {
                    Log::warning('Coach not found in coaches table for session', [
                        'session_id' => $session->new_session_id,
                        'coach_id' => $session->coach_id
                    ]);
                }
                
                // جلب الـ_service من العلاقة
                $service = $session->service;
                $sessionType = 'N/A'; // سيتم استخدامه لـ service_type
                $serviceTitle = 'N/A'; // حقل جديد للـ title
                $currentParticipants = null; // لإضافة عدد المشاركين الحاليين في GroupMentorship

                if ($service) {
                    Log::info('Service found for session', [
                        'session_id' => $session->new_session_id,
                        'service_id' => $session->service_id,
                        'service' => $service->toArray()
                    ]);
                    
                    // تحديد الـ session_type بناءً على الـ service_type
                    if ($service->service_type === 'Mentorship') {
                        $sessionType = 'mentorship sessions';
                    } else {
                        $sessionType = strtolower(str_replace('_', ' ', $service->service_type)); // مثل "group mentorship"
                    }

                    // تحديد الـ service_title بناءً على الجداول الفرعية
                    if ($service->service_type === 'Mentorship') {
                        $mentorship = $service->mentorship;
                        if ($mentorship) {
                            Log::info('Mentorship found for service', [
                                'service_id' => $service->service_id,
                                'mentorship' => $mentorship->toArray()
                            ]);
                            if ($mentorship->mentorship_type === 'Mentorship session') {
                                // تحقق من جدول mentorship_sessions
                                $mentorshipSession = $service->mentorshipSession;
                                if ($mentorshipSession) {
                                    Log::info('Mentorship session found for service', [
                                        'service_id' => $service->service_id,
                                        'mentorship_session' => $mentorshipSession->toArray()
                                    ]);
                                    $serviceTitle = $mentorshipSession->session_type; // مثل "CV Review"
                                } else {
                                    Log::warning('Mentorship session not found for service', [
                                        'service_id' => $service->service_id
                                    ]);
                                    $serviceTitle = 'Mentorship Session';
                                }
                            } elseif ($mentorship->mentorship_type === 'Mentorship plan') {
                                $serviceTitle = 'Mentorship Plan';
                            }
                        } else {
                            Log::warning('No mentorship found for service', [
                                'service_id' => $service->service_id
                            ]);
                        }
                    } elseif ($service->service_type === 'Mock_Interview') {
                        $mockInterview = $service->mockInterview;
                        if ($mockInterview) {
                            Log::info('Mock Interview found for service', [
                                'service_id' => $service->service_id,
                                'mock_interview' => $mockInterview->toArray()
                            ]);
                            $serviceTitle = $mockInterview->interview_type . ' (' . $mockInterview->interview_level . ')';
                        } else {
                            Log::warning('Mock Interview not found for service', [
                                'service_id' => $service->service_id
                            ]);
                            $serviceTitle = 'Mock Interview';
                        }
                    } elseif ($service->service_type === 'Group_Mentorship') {
                        $groupMentorship = $service->groupMentorship;
                        if ($groupMentorship) {
                            Log::info('Group Mentorship found for service', [
                                'service_id' => $service->service_id,
                                'group_mentorship' => $groupMentorship->toArray()
                            ]);
                            $serviceTitle = $groupMentorship->title; // مثل "Weekly Coding Bootcamp3"
                            $currentParticipants = $groupMentorship->current_participants; // عدد المشاركين الحاليين
                        } else {
                            Log::warning('Group Mentorship not found for service', [
                                'service_id' => $service->service_id
                            ]);
                            $serviceTitle = 'Group Mentorship';
                            $currentParticipants = 0;
                        }
                    } else {
                        $serviceTitle = $service->title ?? 'N/A';
                    }
                } else {
                    Log::warning('Service not found for session', [
                        'session_id' => $session->new_session_id,
                        'service_id' => $session->service_id
                    ]);
                }

                // حساب وقت النهاية بناءً على المدة مع تحويل إلى EEST
                $startTime = Carbon::parse($session->date_time)->addHours(3); // UTC to EEST
                $endTime = $startTime->copy()->addMinutes($session->duration);
                
                // تنسيق التاريخ والوقت بالشكل المطلوب
                $date = $startTime->format('D, M d');
                $startTimeFormatted = $startTime->format('h:i A');
                $endTimeFormatted = $endTime->format('h:i A');
                $timeRange = "$startTimeFormatted - $endTimeFormatted";

                $sessionData = [
                    'new_session_id' => $session->new_session_id,
                    'session_type' => $sessionType,
                    'title' => $serviceTitle,
                    'date' => $date,
                    'time_range' => $timeRange,
                    'status' => $session->status,
                    'meeting_link' => $session->meeting_link,
                    'coach_name' => $coachName,
                ];

                // إضافة current_participants إذا كان الـ session من نوع GroupMentorship
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
            return response()->json(['message' => 'Unauthorized'], 403);
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

        // التأكد أن المستخدم هو الكوتش أو الترايني لهذه الجلسة
        $isAuthorized = false;
        if ($user->role_profile === 'Coach') {
            $isAuthorized = $session->coach_id == $user->User_ID;
        } elseif ($user->role_profile === 'Trainee') {
            $isAuthorized = $session->trainee_id == $user->User_ID;
        }

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

        $sessionEndTime = Carbon::parse($session->date_time)->addHours(3)->addMinutes($session->duration); // UTC to EEST
        if (Carbon::now()->lt($sessionEndTime)) {
            Log::warning('Session cannot be completed yet', [
                'session_id' => $sessionId,
                'end_time' => $sessionEndTime->toDateTimeString()
            ]);
            return response()->json(['message' => 'Session cannot be completed yet. It has not ended.'], 400);
        }

        $session->status = 'completed';
        $session->save();

        Log::info('Session marked as completed', [
            'session_id' => $sessionId
        ]);

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

        $isAuthorized = false;
        if ($user->role_profile === 'Coach') {
            $isAuthorized = $session->coach_id == $user->User_ID;
        } elseif ($user->role_profile === 'Trainee') {
            $isAuthorized = $session->trainee_id == $user->User_ID;
        }

        if (!$isAuthorized) {
            Log::warning('Unauthorized attempt to cancel session', [
                'user_id' => $user->User_ID,
                'session_id' => $sessionId
            ]);
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($session->status !== 'Scheduled' && $session->status !== 'pending') {
            Log::warning('Session cannot be cancelled', [
                'session_id' => $sessionId,
                'status' => $session->status
            ]);
            return response()->json(['message' => 'Session cannot be cancelled'], 400);
        }

        $session->status = 'cancelled';
        $session->save();

        Log::info('Session cancelled', [
            'session_id' => $sessionId
        ]);

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
        $request->validate([
            'meeting_link' => 'required|url'
        ]);

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
            'session' => $session
        ]);
    }
}
