<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CoachResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CoachController extends Controller
{
    /**
     * Fetch coaches with their details for the Explore Coaches page
     */
    public function exploreCoaches(Request $request)
    {
        // جلب المستخدم المسجل حاليًا
        $currentUser = auth()->user();

        // التحقق إن المستخدم هو Trainee
        if ($currentUser->role_profile !== 'Trainee') {
            return response()->json(['message' => 'Unauthorized: Only Trainees can access this endpoint'], 403);
        }

        $search = $request->query('search', ''); // Search query for multiple fields
        $perPage = $request->query('per_page', 10); // Pagination: number of coaches per page

        $coachesQuery = User::with(['coachProfile', 'services.prices', 'services.sessions', 'skills', 'reviews'])
            ->where('role_profile', 'Coach') // Only fetch users with role 'Coach'
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    // السيرش في full_name (من جدول users)
                    $q->where('full_name', 'like', "%{$search}%")
                      // السيرش في Title (من جدول coaches)
                      ->orWhereHas('coachProfile', function ($q) use ($search) {
                          $q->where('Title', 'like', "%{$search}%");
                      })
                      // السيرش في service_type (من جدول services)
                      ->orWhereHas('services', function ($q) use ($search) {
                          $q->where('service_type', 'like', "%{$search}%");
                      })
                      // السيرش في skill (من جدول coach_skills)
                      ->orWhereHas('skills', function ($q) use ($search) {
                          $q->where('skill', 'like', "%{$search}%");
                      });
                });
            });

        $coaches = $coachesQuery->paginate($perPage);

        Log::info('Fetching coaches for Explore Coaches page', [
            'search' => $search,
            'coaches_count' => $coaches->total(),
            'trainee_id' => $currentUser->User_ID,
        ]);

        return CoachResource::collection($coaches);
    }
}
