<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\NewSession;
use App\Models\Book;
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
            $statuses = ['Pending'];
            $timeCondition = ['date_time', '>=', now()];
        } elseif ($type === 'history') {
            $statuses = ['Completed', 'Cancelled'];
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
            $query = NewSession::with(['service.user', 'books.trainee'])
                ->whereHas('service', function ($query) use ($user) {
                    $query->where('coach_id', $user->User_ID);
                })
                ->whereIn('status', $statuses);

            if ($timeCondition) {
                $query->where($timeCondition[0], $timeCondition[1], $timeCondition[2]);
            }

            $sessions = $query->get()->map(function ($session) {
                $trainee = $session->books->first() ? $session->books->first()->trainee : null;
                return [
                    'new_session_id' => $session->new_session_id,
                    'date_time' => $session->date_time,
                    'duration' => $session->duration,
                    'status' => $session->status,
                    'meeting_link' => $session->meeting_link,
                    'trainee_name' => $trainee ? $trainee->name : 'N/A',
                ];
            });

            Log::info("Fetching $type sessions for Coach", [
                'user_id' => $user->User_ID,
                'sessions_count' => $sessions->count(),
                'sessions' => $sessions->toArray()
            ]);
        } elseif ($user->role_profile === 'Trainee') {
            $query = NewSession::with(['service.user', 'books.trainee'])
                ->whereHas('books', function ($query) use ($user) {
                    $query->where('trainee_id', $user->User_ID);
                })
                ->whereIn('status', $statuses);

            if ($timeCondition) {
                $query->where($timeCondition[0], $timeCondition[1], $timeCondition[2]);
            }

            $sessions = $query->get()->map(function ($session) {
                $coach = $session->service->user ?? null;
                return [
                    'new_session_id' => $session->new_session_id,
                    'date_time' => $session->date_time,
                    'duration' => $session->duration,
                    'status' => $session->status,
                    'meeting_link' => $session->meeting_link,
                    'coach_name' => $coach ? $coach->name : 'N/A',
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
     * قبول طلب منتورشيب من الكوتش وإنشاء جلسة جديدة
     */
    public function acceptSession($requestId)
    {
        $user = Auth::user();
        $mentorshipRequest = \App\Models\MentorshipRequest::findOrFail($requestId);

        if ($user->role_profile !== 'Coach' || $mentorshipRequest->coach_id !== $user->User_ID) {
            Log::warning('Unauthorized attempt to accept mentorship request', [
                'user off session->user_id' => $user->User_ID,
                'request_id' => $requestId
            ]);
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($session->status !== 'pending') {
            Log::warning('Request cannot be accepted', [
                'request_id' => $requestId,
                'status' => $mentorshipRequest->status
            ]);
            return response()->json(['message' => 'Request cannot be accepted'], 400);
        }

        $mentorshipRequest->status = 'accepted';
        $mentorshipRequest->save();

        $session = new NewSession();
        $session->date_time = $mentorshipRequest->first_session_time;
        $session->duration = $mentorshipRequest->duration_minutes;
        $session->status = 'Scheduled';
        $session->service_id = $mentorshipRequest->service_id;
        $session->mentorship_request_id = $mentorshipRequest->id;
        $session->meeting_link = null;
        $session->save();

        $book = new Book();
        $book->trainee_id = $mentorshipRequest->trainee_id;
        $book->session_id = $session->new_session_id;
        $book->save();

        Log::info('Mentorship request accepted and session scheduled', [
            'request_id' => $requestId,
            'session_id' => $session->new_session_id
        ]);

        return response()->json([
            'message' => 'Request accepted and session scheduled successfully!',
            'session' => $session
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
            $isAuthorized = NewSession::where('new_session_id', $sessionId)
                ->whereHas('service', function ($query) use ($user) {
                    $query->where('coach_id', $user->User_ID);
                })->exists();
        } elseif ($user->role_profile === 'Trainee') {
            $isAuthorized = NewSession::where('new_session_id', $sessionId)
                ->whereHas('books', function ($query) use ($user) {
                    $query->where('trainee_id', $user->User_ID);
                })->exists();
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

        $session->status = 'Completed';
        $session->save();

        if ($session->mentorship_request_id) {
            $mentorshipRequest = \App\Models\MentorshipRequest::find($session->mentorship_request_id);
            if ($mentorshipRequest) {
                $mentorshipRequest->status = 'completed';
                $mentorshipRequest->save();
            }
        }

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
            $isAuthorized = NewSession::where('new_session_id', $sessionId)
                ->whereHas('service', function ($query) use ($user) {
                    $query->where('coach_id', $user->User_ID);
                })->exists();
        } elseif ($user->role_profile === 'Trainee') {
            $isAuthorized = NewSession::where('new_session_id', $sessionId)
                ->whereHas('books', function ($query) use ($user) {
                    $query->where('trainee_id', $user->User_ID);
                })->exists();
        }

        if (!$isAuthorized) {
            Log::warning('Unauthorized attempt to cancel session', [
                'user_id' => $user->User_ID,
                'session_id' => $sessionId
            ]);
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($session->status !== 'Scheduled' && $session->status !== 'Pending') {
            Log::warning('Session cannot be cancelled', [
                'session_id' => $sessionId,
                'status' => $session->status
            ]);
            return response()->json(['message' => 'Session cannot be cancelled'], 400);
        }

        $session->status = 'Cancelled';
        $session->save();

        if ($session->mentorship_request_id) {
            $mentorshipRequest = \App\Models\MentorshipRequest::find($session->mentorship_request_id);
            if ($mentorshipRequest) {
                $mentorshipRequest->status = 'rejected';
                $mentorshipRequest->save();
            }
        }

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

        $isCoachSession = NewSession::where('new_session_id', $sessionId)
            ->whereHas('service', function ($query) use ($user) {
                $query->where('coach_id', $user->User_ID);
            })->exists();

        if (!$isCoachSession) {
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
