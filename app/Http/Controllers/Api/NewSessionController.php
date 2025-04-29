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
            $query = NewSession::with(['service', 'trainees'])
                ->where('coach_id', $user->User_ID)
                ->whereIn('status', $statuses);

            if ($timeCondition) {
                $query->where($timeCondition[0], $timeCondition[1], $timeCondition[2]);
            }

            $sessions = $query->get()->map(function ($session) {
                // جلب الـ trainee من العلاقة
                $trainee = $session->trainees->first();
                if (!$trainee) {
                    Log::warning('Trainee not found for session', [
                        'session_id' => $session->new_session_id,
                        'trainee_id' => $session->trainee_id
                    ]);
                }
                
                // جلب الـ service من العلاقة
                $service = $session->service;
                $serviceName = 'N/A';

                if ($service) {
                    Log::info('Service found for session', [
                        'session_id' => $session->new_session_id,
                        'service_id' => $session->service_id,
                        'service' => $service->toArray()
                    ]);
                    if ($service->service_type === 'Mentorships') {
                        if ($service->mentorship_type === 'Mentorship Sessions') {
                            $serviceName = $service->sub_type;
                        } elseif ($service->mentorship_type === 'Mentorship Plan') {
                            $serviceName = 'Mentorship Plan';
                        } elseif ($service->mentorship_type === 'Group Mentorship') {
                            $serviceName = 'Group Mentorship';
                        }
                    } else {
                        $serviceName = $service->title;
                    }
                } else {
                    Log::warning('Service not found for session', [
                        'session_id' => $session->new_session_id,
                        'service_id' => $session->service_id
                    ]);
                }

                // حساب وقت النهاية بناءً على المدة
                $endTime = Carbon::parse($session->date_time)->addMinutes($session->duration);
                
                // تنسيق التاريخ والوقت بالشكل المطلوب
                $date = Carbon::parse($session->date_time)->format('D, M d');
                $startTimeFormatted = Carbon::parse($session->date_time)->format('h:i A');
                $endTimeFormatted = $endTime->format('h:i A');
                $timeRange = "$startTimeFormatted - $endTimeFormatted";

                // تحديد نوع الجلسة بناءً على الحالة
                $sessionType = 'N/A';
                if ($session->status === 'Scheduled') {
                    $sessionType = 'Mentorship session';
                } elseif ($session->status === 'completed') {
                    $sessionType = 'completed session';
                } elseif ($session->status === 'cancelled') {
                    $sessionType = 'cancelled session';
                }

                return [
                    'new_session_id' => $session->new_session_id,
                    'session_type' => $sessionType,
                    'date' => $date,
                    'time_range' => $timeRange,
                    'status' => $session->status,
                    'meeting_link' => $session->meeting_link,
                    'trainee_name' => $trainee ? $trainee->name : 'N/A',
                    'service_name' => $serviceName,
                ];
            });

            Log::info("Fetching $type sessions for Coach", [
                'user_id' => $user->User_ID,
                'sessions_count' => $sessions->count(),
                'sessions' => $sessions->toArray()
            ]);
        } elseif ($user->role_profile === 'Trainee') {
            $query = NewSession::with(['service', 'coach'])
                ->where('trainee_id', $user->User_ID)
                ->whereIn('status', $statuses);

            if ($timeCondition) {
                $query->where($timeCondition[0], $timeCondition[1], $timeCondition[2]);
            }

            $sessions = $query->get()->map(function ($session) {
                // جلب الـ coach من العلاقة
                $coach = $session->coach;
                if (!$coach) {
                    Log::warning('Coach not found for session', [
                        'session_id' => $session->new_session_id,
                        'coach_id' => $session->coach_id
                    ]);
                } else {
                    Log::info('Coach found for session', [
                        'session_id' => $session->new_session_id,
                        'coach_id' => $session->coach_id,
                        'coach' => $coach->toArray()
                    ]);
                }
                
                // جلب الـ service من العلاقة
                $service = $session->service;
                $serviceName = 'N/A';

                if ($service) {
                    Log::info('Service found for session', [
                        'session_id' => $session->new_session_id,
                        'service_id' => $session->service_id,
                        'service' => $service->toArray()
                    ]);
                    if ($service->service_type === 'Mentorships') {
                        if ($service->mentorship_type === 'Mentorship Sessions') {
                            $serviceName = $service->sub_type;
                        } elseif ($service->mentorship_type === 'Mentorship Plan') {
                            $serviceName = 'Mentorship Plan';
                        } elseif ($service->mentorship_type === 'Group Mentorship') {
                            $serviceName = 'Group Mentorship';
                        }
                    } else {
                        $serviceName = $service->title;
                    }
                } else {
                    Log::warning('Service not found for session', [
                        'session_id' => $session->new_session_id,
                        'service_id' => $session->service_id
                    ]);
                }

                // حساب وقت النهاية بناءً على المدة
                $endTime = Carbon::parse($session->date_time)->addMinutes($session->duration);
                
                // تنسيق التاريخ والوقت بالشكل المطلوب
                $date = Carbon::parse($session->date_time)->format('D, M d');
                $startTimeFormatted = Carbon::parse($session->date_time)->format('h:i A');
                $endTimeFormatted = $endTime->format('h:i A');
                $timeRange = "$startTimeFormatted - $endTimeFormatted";

                // تحديد نوع الجلسة بناءً على الحالة
                $sessionType = 'N/A';
                if ($session->status === 'Scheduled') {
                    $sessionType = 'Mentorship session';
                } elseif ($session->status === 'completed') {
                    $sessionType = 'completed session';
                } elseif ($session->status === 'cancelled') {
                    $sessionType = 'cancelled session';
                }

                return [
                    'new_session_id' => $session->new_session_id,
                    'session_type' => $sessionType,
                    'date' => $date,
                    'time_range' => $timeRange,
                    'status' => $session->status,
                    'meeting_link' => $session->meeting_link,
                    'coach_name' => $coach ? $coach->name : 'N/A',
                    'service_name' => $serviceName,
                ];
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

        $sessionEndTime = Carbon::parse($session->date_time)->addMinutes($session->duration);
        if (Carbon::now()->lt($sessionEndTime)) {
            Log::warning('Session cannot be completed yet', [
                'session_id' => $sessionId,
                'end_time' => $sessionEndTime
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
            'meeting_link' => $session->meeting_link
        ]);
    }

    /**
     * لما يحصل رفض لطلب Mentorship Request، يتم إلغاء الجلسات المرتبطة بيه
     */
    public function rejectMentorshipRequest($mentorshipRequestId)
    {
        $user = Auth::user();

        // التأكد إن المستخدم هو الكوتش بتاع الطلب
        $mentorshipRequest = \App\Models\MentorshipRequest::findOrFail($mentorshipRequestId);

        // التأكد إن المستخدم هو الكوتش بتاع الطلب
        if ($mentorshipRequest->coach_id != $user->User_ID || $user->role_profile !== 'Coach') {
            Log::warning('Unauthorized attempt to reject mentorship request', [
                'user_id' => $user->User_ID,
                'mentorship_request_id' => $mentorshipRequestId
            ]);
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // التأكد إن الطلب مرفوض بالفعل (لأن ده بيحصل في MentorshipRequestController)
        if ($mentorshipRequest->status !== 'rejected') {
            Log::warning('Mentorship request is not rejected yet', [
                'mentorship_request_id' => $mentorshipRequestId,
                'status' => $mentorshipRequest->status
            ]);
            return response()->json(['message' => 'Mentorship request must be rejected first'], 400);
        }

        // التأكد إن الدفع لسه ما اكتملش (خاص بـ Group Mentorship)
        $pendingPayment = \App\Models\PendingPayment::where('mentorship_request_id', $mentorshipRequestId)->first();
        if ($pendingPayment) {
            $payment = \App\Models\Payment::where('mentorship_request_id', $mentorshipRequestId)
                ->where('payment_status', 'completed')
                ->first();
            if ($payment) {
                Log::warning('Cannot cancel sessions, payment already completed', [
                    'mentorship_request_id' => $mentorshipRequestId
                ]);
                return response()->json(['message' => 'Cannot cancel sessions, payment already completed'], 400);
            }
        }

        // جلب الجلسات المرتبطة بالطلب
        $sessions = NewSession::where('mentorship_request_id', $mentorshipRequestId)
            ->whereIn('status', ['pending', 'Scheduled'])
            ->get();

        // تحديث حالة الجلسات لـ cancelled
        foreach ($sessions as $session) {
            $session->status = 'cancelled';
            $session->save();

            Log::info('Session cancelled due to mentorship request rejection', [
                'session_id' => $session->id,
                'mentorship_request_id' => $mentorshipRequestId
            ]);
        }

        Log::info('Associated sessions cancelled after mentorship request rejection', [
            'mentorship_request_id' => $mentorshipRequestId,
            'sessions_count' => $sessions->count()
        ]);

        return response()->json([
            'message' => 'Associated sessions cancelled successfully!',
            'cancelled_sessions_count' => $sessions->count()
        ]);
    }
}
