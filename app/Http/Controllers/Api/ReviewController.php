<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
   
        // عرض جميع المراجعات
        public function index()
        {
            $reviews = Review::with(['trainee.user', 'coach.user'])->get();
            return response()->json($reviews);
        }
    
        // عرض مراجعات مدرب معين
        public function show($coach_id)
        {
            // التأكد إن الكوتش اللي بيشوف الريفيوز هو نفسه الكوتش المطلوب
            $currentUser = auth()->user();
            if (!$currentUser->coach || $currentUser->coach->User_ID != $coach_id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        
            $reviews = Review::with(['trainee.user', 'coach.user'])
                ->where('coach_id', $coach_id)
                ->get();
            return response()->json($reviews);
        }
    
        // إضافة مراجعة جديدة
        public function store(Request $request)
{
    // التأكد إن المستخدم هو Trainee
    $currentUser = auth()->user();
    if ($currentUser->role_profile !== 'Trainee' || !$currentUser->trainee) {
        return response()->json(['message' => 'Only trainees can submit reviews'], 403);
    }

    // التحقق من البيانات المدخلة
    $validated = $request->validate([
        'coach_id' => 'required|exists:coaches,User_ID',
        'rating' => 'required|in:1,2,3,4,5',
        'comment' => 'nullable|string',
    ]);

    // إضافة الريفيو
    $review = Review::create([
        'trainee_id' => $currentUser->trainee->User_ID,
        'coach_id' => $validated['coach_id'],
        'rating' => $validated['rating'],
        'comment' => $validated['comment'],
    ]);

    return response()->json($review, 201);
}
}

