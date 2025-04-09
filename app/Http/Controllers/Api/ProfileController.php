<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CoachResource;
use App\Http\Resources\TraineeResource;
use App\Models\Coach;
use App\Models\Trainee;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function getCoachDashboard(Request $request, $user_id)
    {
        // جلب المستخدم المسجل حاليًا
        $currentUser = auth()->user();

        // التحقق إن المستخدم المسجل هو نفسه اللي بيحاول يشوف الداشبورد (اختياري)
        if ($currentUser->User_ID != $user_id) {
            return response()->json(['message' => 'Unauthorized: You can only view your own dashboard'], 403);
        }

        // التحقق إن المستخدم هو Coach
        if ($currentUser->Role_Profile !== 'Coach') {
            return response()->json(['message' => 'You are not a coach'], 403);
        }

        // جلب بيانات الكوتش بناءً على user_id
        $coach = Coach::with([
            'user',
            'skills',
            'languages',
            'availabilities',
            'reviews.trainee.user'
        ])->findOrFail($user_id);

        return new CoachResource($coach);
    }

    public function getTraineeDashboard(Request $request, $user_id)
    {
        // جلب المستخدم المسجل حاليًا
        $currentUser = auth()->user();

        // التحقق إن المستخدم المسجل هو نفسه اللي بيحاول يشوف الداشبورد (اختياري)
        if ($currentUser->User_ID != $user_id) {
            return response()->json(['message' => 'Unauthorized: You can only view your own dashboard'], 403);
        }

        // التحقق إن المستخدم هو Trainee
        if ($currentUser->Role_Profile !== 'Trainee') {
            return response()->json(['message' => 'You are not a trainee'], 403);
        }

        // جلب بيانات التريني بناءً على user_id
        $trainee = Trainee::with([
            'user',
            'preferredLanguages',
            'areasOfInterest',
            'reviews.coach.user'
        ])->findOrFail($user_id);

        return new TraineeResource($trainee);
    }
}