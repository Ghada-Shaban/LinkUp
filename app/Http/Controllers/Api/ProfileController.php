<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CoachResource;
use App\Http\Resources\TraineeResource;
use App\Http\Resources\UserResource;
use App\Models\Coach;
use App\Models\CoachAvailability;
use App\Models\CoachLanguage;
use App\Models\CoachSkill;
use App\Models\Trainee;
use App\Models\TraineeAreaOfInterest;
use App\Models\TraineePreferredLanguage;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function getCoachProfile(Request $request, $user_id)
    {
        // جلب المستخدم المسجل حاليًا
        $currentUser = auth()->user();

        // التحقق إن المستخدم المسجل هو نفسه اللي بيحاول يشوف الداشبورد (اختياري)
        if ($currentUser->User_ID != $user_id) {
            return response()->json(['message' => 'Unauthorized: You can only view your own dashboard'], 403);
        }

     
     

        // جلب بيانات الكوتش بناءً على user_id
        $coach = Coach::with([
            'user',
            'skills',
            'languages',
            'reviews.trainee.user'
        ])->findOrFail($user_id);

        return new CoachResource($coach);
    }

    public function getTraineeProfile(Request $request, $user_id)
    {
        // جلب المستخدم المسجل حاليًا
        $currentUser = auth()->user();

        // التحقق إن المستخدم المسجل هو نفسه اللي بيحاول يشوف الداشبورد (اختياري)
        if ($currentUser->User_ID != $user_id) {
            return response()->json(['message' => 'Unauthorized: You can only view your own dashboard'], 403);
        }

        

        // جلب بيانات التريني بناءً على user_id
        $trainee = Trainee::with([
            'user',
            'preferredLanguages',
            'areasOfInterest'
        ])->findOrFail($user_id);

        return new TraineeResource($trainee);
    }
}
