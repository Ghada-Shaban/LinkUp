<?php

namespace App\Http\Controllers;

use App\Models\Review;
use Illuminate\Http\Request;

class LandingPageReviewController extends Controller
{
    public function getReviews()
    {
        try {
            // جلب الـ Reviews مع بيانات الـ Trainee والـ Coach
            $reviews = Review::with(['trainee.user', 'coach.user'])
                ->select('id', 'trainee_id', 'coach_id', 'rating', 'comment')
                ->get()
                ->map(function ($review) {
                    return [
                        'id' => $review->id,
                        'trainee_name' => $review->trainee->user->name ?? 'Unknown',
                        'coach_name' => $review->coach->user->name ?? 'Unknown',
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
