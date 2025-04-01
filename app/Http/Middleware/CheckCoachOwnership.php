<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckCoachOwnership
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): Response
    {
        // جلب coachId من الـ Route
        $coachId = $request->route('coachId');

        // التحقق من إن المستخدم مسجل دخوله وإن User_ID بتاعه يساوي coachId
        if (!auth()->check() || auth()->user()->User_ID != $coachId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return $next($request);
    }
}