<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\NewSession;
use App\Models\Service;
use App\Models\User;
use App\Models\MentorshipPlan;
use App\Models\GroupMentorship;
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

            $rawSessions = $query->get();

            // تجميع جلسات Group Mentorship
            $groupMentorshipSessions = [];
            $otherSessions = [];
            $groupMentorshipTrainees = [];

            foreach ($rawSessions as $session) {
                $service = $session->service;
                if ($service && $service->service_type === 'Group_Mentorship') {
                    $groupMentorship = $service->groupMentorship;
                    if ($groupMentorship) {
                        $dateTime = Carbon::parse($session->date_time);
                        $dateKey = $dateTime->toDateString() . '-' . $groupMentorship->id;

                        // تجميع الجلسات
                        if (!isset($groupMentorshipSessions[$dateKey])) {
                            $groupMentorshipSessions[$dateKey] = [
                                'session' => $session,
                                'group_mentorship' => $groupMentorship,
                                'date_time' => $dateTime
                            ];
                        }

                        // تجميع معرفات المتدربين من جدول NewSession بناءً على service_id وتاريخ
                        $gmKey = $groupMentorship->id . '-' . $dateTime->toDateString();
                        if (!isset($groupMentorshipTrainees[$gmKey])) {
                            $groupMentorshipTrainees[$gmKey] = NewSession::where('service_id', $session->service_id)
                                ->whereDate('date_time', $dateTime->toDateString())
                                ->whereIn('status', $statuses)
                                ->pluck('trainee_id')
                                ->toArray();
                        }
                    }
                } else {
                    $otherSessions[] = $session;
                }
            }

            // فرز جلسات Group Mentorship حسب date_time
            $groupMentorshipSessions = collect($groupMentorshipSessions)->sortBy(function ($item) {
                return $item['date_time'];
            })->values()->all();

            // معالجة الجلسات
            $sessions = collect(array_merge($groupMentorshipSessions, $otherSessions))->map(function ($item) use ($user, $groupMentorshipTrainees) {
                $session = is_array($item) ? $item['session'] : $item;
                $groupMentorship = is_array($item) ? $item['group_mentorship'] : null;
                $dateTime = is_array($item) ? $item['date_time'] : Carbon::parse($session->date_time);

                $service = $session->service;
                $sessionType = 'N/A';
                $serviceTitle = 'N/A';
                $currentParticipants = null;
                $traineeNames = null;

                if ($service) {
                    if ($service->service_type === 'Mentorship') {
                        $mentorship = $service->mentorship;
                        if ($mentorship) {
                            if (strtolower($mentorship->mentorship_type) === 'mentorship session') {
                                $sessionType = 'mentorship sessions';
                                $mentorshipSession = $mentorship->mentorshipSession;
                                $serviceTitle = $mentorshipSession ? $mentorshipSession->session_type : 'Mentorship Session';
                            } elseif (strtolower($mentorship->mentorship_type) === 'mentorship plan') {
                                $sessionType = 'mentorship plan';
                                $mentorshipPlan = $mentorship->mentorshipPlan;
                                $serviceTitle = $mentorshipPlan ? $mentorshipPlan->title : 'Mentorship Plan';
                            } else {
                                $sessionType = 'mentorship';
                                $serviceTitle = 'Unknown Mentorship';
                            }
                        } else {
                            $mentorshipPlan = MentorshipPlan::where('service_id', $session->service_id)->first();
                            if ($mentorshipPlan) {
                                $sessionType = 'mentorship plan';
                                $serviceTitle = $mentorshipPlan->title ?? 'Mentorship Plan';
                            } else {
                                $sessionType = 'mentorship';
                                $serviceTitle = 'Mentorship';
                            }
                        }
                    } elseif ($service->service_type === 'Mock_Interview') {
                        $sessionType = 'mock interview';
                        $mockInterview = $service->mockInterview;
                        $serviceTitle = $mockInterview ? $mockInterview->interview_type . ' (' . $mockInterview->interview_level . ')' : 'Mock Interview';
                    } elseif ($service->service_type === 'Group_Mentorship') {
                        $sessionType = 'group mentorship';
                        $serviceTitle = $groupMentorship ? $groupMentorship->title : 'Group Mentorship';

                        // استرجاع أسماء المتدربين بناءً على trainee_ids من new_sessions
                        $gmKey = $groupMentorship->id . '-' . $dateTime->toDateString();
                        $traineeIds = isset($groupMentorshipTrainees[$gmKey]) ? $groupMentorshipTrainees[$gmKey] : [];
                        $traineeNames = [];
                        if (!empty($traineeIds)) {
                            $trainees = User::whereIn('User_ID', $traineeIds)->pluck('full_name')->toArray();
                            $traineeNames = $trainees; // جيب كل الأسماء بدون تصفية
                            sort($traineeNames); // فرز الأسماء أبجديًا
                        }
                        $traineeNames = !empty($traineeNames) ? array_values($traineeNames) : ['N/A'];
                        $currentParticipants = count($traineeNames) > 1 || (count($traineeNames) === 1 && $traineeNames[0] !== 'N/A') ? count($traineeNames) : 0;
                    } else {
                        $sessionType = strtolower(str_replace('_', ' ', $service->service_type));
                        $serviceTitle = $service->title ?? 'N/A';
                    }
                }

                $isMentorshipPlan = $session->mentorshipRequest && $session->mentorshipRequest->requestable_type === \App\Models\MentorshipPlan::class;
                if (!$isMentorshipPlan) {
                    $dateTime->addHours(3);
                }

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
                    'created_at' => $createdAt->toDateTimeString(),
                    'updated_at' => $updatedAt->toDateTimeString()
                ];

                if ($service) {
                    $traineeName = $session->trainees->first() ? $session->trainees->first()->full_name : 'N/A';
                    $sessionData['trainee_name'] = $traineeName;
                }

                if ($service && $service->service_type === 'Group_Mentorship') {
                    $sessionData['current_participants'] = $currentParticipants;
                    $sessionData['trainee_names'] = $traineeNames;
                    $sessionData['coach_id'] = $session->coach ? $session->coach->User_ID : null;
                    $sessionData['coach_name'] = $session->coach && $session->coach->user ? $session->coach->user->full_name : 'N/A';
                } else {
                    $sessionData['coach_name'] = $session->coach && $session->coach->user ? $session->coach->user->full_name : 'N/A';
                    $sessionData['coach_id'] = $session->coach ? $session->coach->User_ID : null;
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

            $rawSessions = $query->get();

            // تجميع جلسات Group Mentorship
            $groupMentorshipSessions = [];
            $otherSessions = [];
            $groupMentorshipTrainees = [];

            foreach ($rawSessions as $session) {
                $service = $session->service;
                if ($service && $service->service_type === 'Group_Mentorship') {
                    $groupMentorship = $service->groupMentorship;
                    if ($groupMentorship) {
                        $dateTime = Carbon::parse($session->date_time);
                        $dateKey = $dateTime->toDateString() . '-' . $groupMentorship->id;

                        // تجميع الجلسات
                        if (!isset($groupMentorshipSessions[$dateKey])) {
                            $groupMentorshipSessions[$dateKey] = [
                                'session' => $session,
                                'group_mentorship' => $groupMentorship,
                                'date_time' => $dateTime
                            ];
                        }

                        // تجميع معرفات المتدربين من جميع الجلسات بنفس service_id وتاريخ
                        $gmKey = $groupMentorship->id . '-' . $dateTime->toDateString();
                        if (!isset($groupMentorshipTrainees[$gmKey])) {
                            // استرجاع جميع trainee_ids من الجلسات المرتبطة بنفس service_id وتاريخ
                            $relatedSessions = NewSession::where('service_id', $session->service_id)
                                ->whereDate('date_time', $dateTime->toDateString())
                                ->whereIn('status', $statuses)
                                ->pluck('trainee_id')
                                ->toArray();
                            $groupMentorshipTrainees[$gmKey] = array_unique(array_filter($relatedSessions));
                        }
                    }
                } else {
                    $otherSessions[] = $session;
                }
            }

            // فرز جلسات Group Mentorship حسب date_time
            $groupMentorshipSessions = collect($groupMentorshipSessions)->sortBy(function ($item) {
                return $item['date_time'];
            })->values()->all();

            // معالجة الجلسات
            $sessions = collect(array_merge($groupMentorshipSessions, $otherSessions))->map(function ($item) use ($user, $groupMentorshipTrainees) {
                $session = is_array($item) ? $item['session'] : $item;
                $groupMentorship = is_array($item) ? $item['group_mentorship'] : null;
                $dateTime = is_array($item) ? $item['date_time'] : Carbon::parse($session->date_time);

                $service = $session->service;
                $sessionType = 'N/A';
                $serviceTitle = 'N/A';
                $currentParticipants = null;
                $traineeNames = null;

                if ($service) {
                    if ($service->service_type === 'Mentorship') {
                        $mentorship = $service->mentorship;
                        if ($mentorship) {
                            if (strtolower($mentorship->mentorship_type) === 'mentorship session') {
                                $sessionType = 'mentorship sessions';
                                $mentorshipSession = $mentorship->mentorshipSession;
                                $serviceTitle = $mentorshipSession ? $mentorshipSession->session_type : 'Mentorship Session';
                            } elseif (strtolower($mentorship->mentorship_type) === 'mentorship plan') {
                                $sessionType = 'mentorship plan';
                                $mentorshipPlan = $session->mentorshipRequest && $session->mentorshipRequest->requestable
                                    ? $session->mentorshipRequest->requestable
                                    : $mentorship->mentorshipPlan;
                                $serviceTitle = $mentorshipPlan ? $mentorshipPlan->title : 'Mentorship Plan';
                            } else {
                                $sessionType = 'mentorship';
                                $serviceTitle = 'Unknown Mentorship';
                            }
                        } else {
                            $mentorshipPlan = MentorshipPlan::where('service_id', $session->service_id)->first();
                            if ($mentorshipPlan) {
                                $sessionType = 'mentorship plan';
                                $serviceTitle = $mentorshipPlan->title ?? 'Mentorship Plan';
                            } else {
                                $sessionType = 'mentorship';
                                $serviceTitle = 'Mentorship';
                            }
                        }
                    } elseif ($service->service_type === 'Mock_Interview') {
                        $sessionType = 'mock interview';
                        $mockInterview = $service->mockInterview;
                        $serviceTitle = $mockInterview ? $mockInterview->interview_type . ' (' . $mockInterview->interview_level . ')' : 'Mock Interview';
                    } elseif ($service->service_type === 'Group_Mentorship') {
                        $sessionType = 'group mentorship';
                        $serviceTitle = $groupMentorship ? $groupMentorship->title : 'Group Mentorship';

                        // استرجاع أسماء المتدربين بناءً على trainee_ids من new_sessions
                        $gmKey = $groupMentorship->id . '-' . $dateTime->toDateString();
                        $traineeIds = isset($groupMentorshipTrainees[$gmKey]) ? $groupMentorshipTrainees[$gmKey] : [];
                        $traineeNames = [];
                        if (!empty($traineeIds)) {
                            $trainees = User::whereIn('User_ID', $traineeIds)->pluck('full_name')->toArray();
                            $traineeNames = $trainees; // جيب كل الأسماء بدون تصفية
                            sort($traineeNames); // فرز الأسماء أبجديًا
                        }
                        $traineeNames = !empty($traineeNames) ? array_values($traineeNames) : ['N/A'];
                        $currentParticipants = count($traineeNames) > 1 || (count($traineeNames) === 1 && $traineeNames[0] !== 'N/A') ? count($traineeNames) : 0;
                    } else {
                        $sessionType = strtolower(str_replace('_', ' ', $service->service_type));
                        $serviceTitle = $service->title ?? 'N/A';
                    }
                }

                $isMentorshipPlan = $session->mentorshipRequest && $session->mentorshipRequest->requestable_type === \App\Models\MentorshipPlan::class;
                if (!$isMentorshipPlan) {
                    $dateTime->addHours(3);
                }

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
                    'created_at' => $createdAt->toDateTimeString(),
                    'updated_at' => $updatedAt->toDateTimeString()
                ];

                if ($service) {
                    $traineeName = $session->trainees->first() ? $session->trainees->first()->full_name : 'N/A';
                    $sessionData['trainee_name'] = $traineeName;
                }

                if ($service && $service->service_type === 'Group_Mentorship') {
                    $sessionData['current_participants'] = $currentParticipants;
                    $sessionData['trainee_names'] = $traineeNames;
                    $sessionData['coach_id'] = $session->coach ? $session->coach->User_ID : null;
                    $sessionData['coach_name'] = $session->coach && $session->coach->user ? $session->coach->user->full_name : 'N/A';
                } else {
                    $sessionData['coach_name'] = $session->coach && $session->coach->user ? $session->coach->user->full_name : 'N/A';
                    $sessionData['coach_id'] = $session->coach ? $session->coach->User_ID : null;
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
            "meeting_link" => $session->meeting_link
        ]);

        return response()->json([
            'message' => 'Meeting link updated successfully!',
            'meeting_link' => $session->meeting_link
        ]);
    }
}
