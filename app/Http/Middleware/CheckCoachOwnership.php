<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckCoachOwnership
{
    public function handle(Request $request, Closure $next): Response
    {
        // جلب coachId من الـ Route (ده هيبقى الـ id بتاع الـ Coach من جدول coaches)
        $coachId = (int) $request->route('coachId');

        // التأكد إن المستخدم مسجل دخوله
        $user = auth('api')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // جلب الـ Coach بناءً على الـ id
        $coach = \App\Models\Coach::find($coachId);
        if (!$coach) {
            return response()->json(['message' => 'Coach not found'], 404);
        }

        // التحقق إن الـ user_id بتاع الـ Coach يساوي id بتاع المستخدم المسجل دخوله
        if ($user->id != $coach->User_ID) {
            return response()->json(['message' => 'Unauthorized: You are not the owner of these services'], 403);
        }

        return $next($request);
    }
}
