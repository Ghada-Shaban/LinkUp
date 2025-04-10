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
        $search = $request->query('search', ''); // Search query for name, company, or role
        $perPage = $request->query('per_page', 10); // Pagination: number of coaches per page

        $coachesQuery = User::with(['coachProfile', 'services.prices', 'services.sessions', 'skills', 'reviews'])
            ->where('role_profile', 'Coach') // Only fetch users with role 'Coach'
            ->when($search, function ($query, $search) {
                $query->where('full_name', 'like', "%{$search}%")
                      ->orWhereHas('coachProfile', function ($q) use ($search) {
                          $q->where('Title', 'like', "%{$search}%")
                            ->orWhere('Company_or_School', 'like', "%{$search}%");
                      })
                      ->orWhereHas('skills', function ($q) use ($search) {
                          $q->where('skill', 'like', "%{$search}%");
                      });
            });

        $coaches = $coachesQuery->paginate($perPage);

        Log::info('Fetching coaches for Explore Coaches page', [
            'search' => $search,
            'coaches_count' => $coaches->total(),
        ]);

        return CoachResource::collection($coaches);
    }
}
