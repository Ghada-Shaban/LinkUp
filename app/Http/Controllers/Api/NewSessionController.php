<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\NewSession;
use App\Models\Book;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class NewSessionController extends Controller
{
    /**
     * عرض الجلسات القادمة بناءً على دور المستخدم
     */
    public function index()
    {
        $user = Auth::user();
        $sessions = collect(); // لتخزين الجلسات

        if ($user->role_profile === 'Coach') {
            $sessions = NewSession::whereHas('service', function ($query) use ($user) {
                $query->where('coach_id', $user->User_ID); // غيرنا User_ID لـ coach_id
            })
            ->where('status', 'Scheduled')
            ->where('date_time', '>=', now()) // لضمان عرض الجلسات المستقبلية فقط
            ->get();

            Log::info('Fetching upcoming sessions for Coach', [
                'user_id' => $user->User_ID,
                'sessions_count' => $sessions->count(),
                'sessions' => $sessions->toArray()
            ]);
        } elseif ($user->role_profile === 'Trainee') {
            $sessions = NewSession::whereHas('books', function ($query) use ($user) {
                $query->where('trainee_id', $user->User_ID);
            })
            ->where('status', 'Scheduled')
            ->where('date_time', '>=', now()) // لضمان عرض الجلسات المستقبلية فقط
            ->get();

            Log::info('Fetching upcoming sessions for Trainee', [
                'user_id' => $user->User_ID,
                'sessions_count' => $sessions->count(),
                'sessions' => $sessions->toArray()
            ]);
        } else {
            Log::warning('Unauthorized access to upcoming sessions', [
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
                $query->where('coach_id', $user->User_ID); // غيرنا User_ID لـ coach_id
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
