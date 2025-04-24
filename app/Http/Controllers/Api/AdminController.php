<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\User;
use App\Models\Coach;
use App\Models\CoachAvailability;
use App\Models\CoachSkill;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    /**
 * Get all pending coach registration requests.
 *
 * @param Request $request
 * @return \Illuminate\Http\JsonResponse
 */
public function getPendingCoachRequests(Request $request): \Illuminate\Http\JsonResponse
{
    // Check if the authenticated user is an admin
    $authAdmin = auth('sanctum')->user();
    if (!$authAdmin || !($authAdmin instanceof Admin)) {
        return response()->json(['message' => 'Unauthorized'], 401);
    }

    try {
        $pendingCoaches = Coach::with(['user', 'languages', 'skills'])
            ->where('status', Coach::STATUS_PENDING)
            ->get()
            ->map(function ($coach) {
                return [
                    'user_id' => $coach->User_ID,
                    'full_name' => $coach->user->full_name,
                    'email' => $coach->user->email,
                    'bio' => $coach->bio,
                    'company_or_school' => $coach->company_or_school,
                    'title' => $coach->title,
                    'years_of_experience' => $coach->years_of_experience,
                    'months_of_experience' => $coach->months_of_experience,
                    'linkedin_link' => $coach->user->linkedin_link,
                    'photo' => $coach->user->photo,
                    'languages' => $coach->languages->pluck('language'),
                    'skills' => $coach->skills->pluck('skill'),
                    'status' => $coach->status,
                    'created_at' => $coach->created_at,
                    'updated_at' => $coach->updated_at,
                ];
            });

        return response()->json([
            'message' => 'Pending coach registration requests retrieved successfully',
            'requests' => $pendingCoaches,
        ], 200);
    } catch (\Exception $e) {
        \Log::error('Failed to retrieve pending coach requests', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'message' => 'Failed to retrieve pending coach requests',
            'error' => $e->getMessage(),
        ], 500);
    }
}

/**
 * Approve or reject a coach registration request.
 *
 * @param Request $request
 * @param int $userId
 * @return \Illuminate\Http\JsonResponse
 */
public function handleCoachRequest(Request $request, $userId): \Illuminate\Http\JsonResponse
{
    // Check if the authenticated user is an admin
    $authAdmin = auth('sanctum')->user();
    if (!$authAdmin || !($authAdmin instanceof Admin)) {
        return response()->json(['message' => 'Unauthorized'], 401);
    }

    // Validate the request
    $validated = $request->validate([
        'action' => 'required|in:approve,reject',
    ]);

    try {
        $coach = Coach::where('User_ID', $userId)->firstOrFail();

        if ($coach->status !== Coach::STATUS_PENDING) {
            return response()->json(['message' => 'Request has already been processed'], 400);
        }

        if ($validated['action'] === 'approve') {
            $coach->status = Coach::STATUS_APPROVED;
            $coach->admin_id = $authAdmin->id; // Link the coach to the admin who approved
            $message = 'Coach registration request approved successfully';
        } else {
            $coach->status = Coach::STATUS_REJECTED;
            $message = 'Coach registration request rejected';

            // Delete related data
            CoachLanguage::where('coach_id', $userId)->delete();
            CoachSkill::where('coach_id', $userId)->delete();
            $coach->delete();
            User::where('User_ID', $userId)->delete();

            // Delete photo if exists
            if ($coach->user->photo) {
                \Storage::disk('public')->delete($coach->user->photo);
            }
        }

        $coach->save();

        return response()->json([
            'message' => $message,
            'user_id' => $userId,
            'status' => $coach->status,
        ], 200);
    } catch (\Exception $e) {
        \log::error('Failed to handle coach registration request', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'message' => 'Failed to handle coach registration request',
            'error' => $e->getMessage(),
        ], 500);
    }
}
   
        
           
            
           
          
}