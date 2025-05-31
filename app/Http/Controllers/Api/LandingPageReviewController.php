<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use Illuminate\Http\Request;

class LandingPageReviewController extends Controller
{
    public function getReviews()
    {
        try {
            $reviews = Review::with(['trainee.user', 'coach.user'])
                ->select('id', 'trainee_id', 'coach_id', 'rating', 'comment')
                ->get()
                ->map(function ($review) {
                    $traineeUser = $review->trainee?->user;
                    $coachUser = $review->coach?->user;

                    return [
                        'id' => $review->id,
                        'trainee_name' => $traineeUser ? $traineeUser->full_name : 'Unknown Trainee',
                        'coach_name' => $coachUser ? $coachUser->full_name : 'Unknown Coach',
                        'rating' => $review->rating,
                        'comment' => $review->comment,
                        'coach_experience' => $review->coach->Years_Of_Experience ?? 0,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $reviews,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل في جلب الـ Reviews',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
