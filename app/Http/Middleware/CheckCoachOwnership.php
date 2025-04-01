<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckCoachOwnership
{
    ppublic function handle(Request $request, Closure $next): Response
{
    $coachId = (int) $request->route('coachId');

    $user = auth('api')->user();
    if (!$user) {
        return response()->json(['message' => 'Unauthenticated'], 401);
    }

    $coach = \App\Models\Coach::find($coachId);
    if (!$coach) {
        return response()->json(['message' => 'Coach not found'], 404);
    }

    if ($user->id != $coach->user_id) {
        return response()->json(['message' => 'Unauthorized: You are not the owner of these services'], 403);
    }

    return $next($request);

}
}
