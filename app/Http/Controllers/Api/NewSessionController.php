<?php

namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\NewSession;
use App\Models\Book;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class NewSessionController extends Controller
{
    /**
     * عرض الجلسات القادمة بناءً على دور المستخدم
     */
    public function index()
    {
        $user = Auth::user(); // جلب المستخدم الحالي
        
        if ($user->role_profile === 'Coach') {
            // جلب الجلسات المقبولة فقط (Scheduled) للكوتش
            $sessions = NewSession::whereHas('service', function ($query) use ($user) {
                $query->where('User_ID', $user->User_ID);
            })->where('status', 'Scheduled')->get();
        } elseif ($user->role_profile === 'Trainee') {
            // جلب الجلسات المقبولة فقط (Scheduled) للترايني
            $sessions = NewSession::whereHas('books', function ($query) use ($user) {
                $query->where('trainee_id', $user->User_ID);
            })->where('status', 'Scheduled')->get();
        } else {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'sessions' => $sessions
        ]);
    }

    /**
     * قبول جلسة من الكوتش
     */
    public function acceptSession($sessionId)
    {
        $user = Auth::user();
        $session = NewSession::findOrFail($sessionId);

        // التأكد أن المستخدم هو الكوتش لهذه الجلسة
        $isCoachSession = NewSession::where('new_session_id', $sessionId)
            ->whereHas('service', function ($query) use ($user) {
                $query->where('User_ID', $user->User_ID);
            })->exists();

        if (!$isCoachSession) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($session->status !== 'Pending') {
            return response()->json(['message' => 'Session cannot be accepted'], 400);
        }

        $session->status = 'Scheduled';
        $session->save();

        return response()->json([
            'message' => 'Session accepted successfully!',
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

        // التأكد أن المستخدم هو الكوتش لهذه الجلسة
        $isCoachSession = NewSession::where('new_session_id', $sessionId)
            ->whereHas('service', function ($query) use ($user) {
                $query->where('User_ID', $user->User_ID);
            })->exists();

        if (!$isCoachSession) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $session->meeting_link = $request->meeting_link;
        $session->save();

        return response()->json([
            'message' => 'Meeting link updated successfully!',
            'meeting_link' => $session->meeting_link
        ]);
    }
}

