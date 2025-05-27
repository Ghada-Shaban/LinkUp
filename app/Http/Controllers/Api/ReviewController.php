<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Http\Resources\ReviewResource;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
public function index()
        {
            $reviews = Review::with(['trainee.user', 'coach.user'])->get();
            return response()->json($reviews);
        }
    
public function show($coach_id)
       {
    $currentUser = auth()->user();
    if ($currentUser->role_profile !== 'Coach') {
        return response()->json(['message' => 'You are not a coach'], 403);
    }

    $coach = $currentUser->coach;
    if (!$coach) {
        return response()->json(['message' => 'Coach profile not found'], 403);
    }

    $reviews = Review::with(['trainee.user'])
        ->where('coach_id', $coach->User_ID)
        ->orderBy('created_at', 'desc')
        ->get();

    return ReviewResource::collection($reviews);
}
       
public function store(Request $request)
{
    $currentUser = auth()->user();
    if ($currentUser->role_profile !== 'Trainee') {
        return response()->json(['message' => 'Only trainees can submit reviews'], 403);
    }

    $trainee = $currentUser->trainee;
    if (!$trainee) {
        return response()->json(['message' => 'Trainee profile not found'], 403);
    }

    $validated = $request->validate([
        'coach_id' => 'required|exists:coaches,User_ID',
        'rating' => 'required|in:1,2,3,4,5',
        'comment' => 'nullable|string',
    ]);

    $review = Review::create([
        'trainee_id' => $trainee->User_ID,
        'coach_id' => $validated['coach_id'],
        'rating' => $validated['rating'],
        'comment' => $validated['comment'],
    ]);

    return new ReviewResource($review);
}
}

