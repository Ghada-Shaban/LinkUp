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
            // جلب الجلسات التي تم حجزها مع هذا الكوتش
            $sessions = NewSession::whereHas('service', function ($query) use ($user) {
                $query->where('User_ID', $user->User_ID);
            })->where('status', 'Scheduled')->get();
        } elseif ($user->role_profile === 'Trainee') {
            // جلب الجلسات التي حجزها هذا الترايني
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
