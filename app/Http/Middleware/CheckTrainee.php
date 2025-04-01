<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckTrainee
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::guard('api')->user();

        // التأكد إن المستخدم مسجل دخول
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // التأكد إن المستخدم هو Trainee
        if (!$user->trainee) {
            return response()->json(['message' => 'Unauthorized: Only trainees can perform this action.'], 403);
        }

        return $next($request);
    }
}