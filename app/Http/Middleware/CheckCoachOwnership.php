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
        // جلب coachId من الـ Route وتحويله لـ Integer
        $coachId = (int) $request->route('coachId');

        // التحقق من إن المستخدم مسجل دخوله باستخدام الـ Guard المناسب (api)
        // وإن id بتاعه يساوي coachId
        if (auth('api')->user()->User_ID!= $coachId) {
            return response()->json(['message' => 'Unauthorized: You are not the owner of these services'], 403);
        }

        return $next($request);
    }
}
