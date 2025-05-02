<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\User;
use App\Models\Coach;
use App\Models\CoachLanguage;
use App\Models\CoachSkill;
use App\Models\Trainee;
use App\Models\TraineeAreaOfInterest;
use App\Models\TraineePreferredLanguage;
use App\Models\Review;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Mentorship;
use App\Models\MentorshipRequest;
use App\Models\NewSession;
use App\Models\Book;
use App\Models\CoachAvailability;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminController extends Controller
{
   
public function getPendingCoachRequests(Request $request)
{
    // Check if the authenticated user is an admin
   
  $authAdmin = auth('admin-api')->user();
    if (!$authAdmin) {
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
                    'bio' => $coach->Bio,
                    'company_or_school' => $coach->Company_or_School,
                    'title' => $coach->Title,
                    'years_of_experience' => $coach->Years_Of_Experience,
                    'months_of_experience' => $coach->Months_Of_Experience,
                    'linkedin_link' => $coach->user->linkedin_link,
                    'photo' => $coach->user->photo,
                    'languages' => $coach->languages->pluck('language'),
                    'skills' => $coach->skills->pluck('skill'),
                 
                   
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
public function handleCoachRequest(Request $request, $coachId)
{
    // Check if the authenticated user is an admin
    $authAdmin = auth('admin-api')->user();
    if (!$authAdmin) {
        return response()->json(['message' => 'Unauthorized'], 401);
    }

    // Validate the request
    $validated = $request->validate([
        'action' => 'required|in:approve,reject',
    ]);

    try {
        // Find the coach by its primary key (id)
        $coach = Coach::findOrFail($coachId);

        if ($coach->status !== Coach::STATUS_PENDING) {
            return response()->json(['message' => 'Request has already been processed'], 400);
        }

        // Get the user before any deletion (to access photo if exists)
        $user = $coach->user;

        if ($validated['action'] === 'approve') {
            $coach->status = Coach::STATUS_APPROVED;
            $coach->admin_id = $authAdmin->id; // Link the coach to the admin who approved
            $message = 'Coach registration request approved successfully';
        } else {
            $coach->status = Coach::STATUS_REJECTED;
            $message = 'Coach registration request rejected';

            // Delete related data
            CoachLanguage::where('coach_id', $coach->User_ID)->forceDelete();
            CoachSkill::where('coach_id', $coach->User_ID)->forceDelete();

            // Delete photo if exists
            if ($user && $user->photo) {
                \Storage::disk('public')->delete($user->photo);
            }

            // Delete coach and user (forceDelete to permanently remove)
            $coach->forceDelete();
            User::where('User_ID', $coach->User_ID)->forceDelete();
        }

        // Save the coach (only if not deleted)
        if ($validated['action'] === 'approve') {
            $coach->save();
        }

        return response()->json([
            'message' => $message,
            'coach_id' => $coachId,
            'status' => $coach->status,
        ], 200);
    } catch (\Exception $e) {
        \Log::error('Failed to handle coach registration request', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'message' => 'Failed to handle coach registration request',
        ], 500);
    }
}
    public function getTopCoaches(Request $request)
    {
        // Get top coaches based on average rating
        $topCoaches = Coach::select('coaches.*')
            ->with(['user' => function ($query) {
                $query->select('User_ID', 'full_name','photo','email');
            }])
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->withCount(['sessions as completed_sessions_count' => function ($query) {
                $query->where('status', NewSession::STATUS_COMPLETED);
                }])
            ->orderByDesc('reviews_avg_rating')
            ->take(5) // Top 5 coaches
            ->get()
            ->map(function ($coach) {
                return [
                    'user_id' => $coach->User_ID,
                    'full_name' => $coach->user->full_name,
                    'email' => $coach->user->email,
                     'photo' => $coach->user->photo,
                     'title' => $coach->Title,
                   'completed_sessions' => $coach->completed_sessions_count,
                    'average_rating' => round($coach->reviews_avg_rating, 2),
    
                ];
            });

        // Return the response
        return response()->json([
            'top_coaches' => $topCoaches,
        ], 200);
    }

    public function getApprovedCoaches(Request $request)
    {
    
        $approvedCoaches = Coach::where('status', 'approved')
            ->with(['user' => function ($query) {
                $query->select('User_ID', 'full_name', 'email', 'linkedin_link', 'role_profile', 'photo', 'created_at', 'updated_at');
            }])
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->withCount(['sessions as completed_sessions_count' => function ($query) {
                $query->where('status', NewSession::STATUS_COMPLETED);
                }])
            ->get()
            ->map(function ($coach) {
                return [
                    // From users table
                    'user_id' => $coach->User_ID,
                    'full_name' => $coach->user->full_name,
                    'email' => $coach->user->email,
                    'photo' => $coach->user->photo,
                   'completed_sessions' => $coach->completed_sessions_count,
                  

                    // From coaches table
                    'title' => $coach->Title,
                   
               
                    'years_of_experience' => $coach->Years_Of_Experience,
                    'months_of_experience' => $coach->Months_Of_Experience,
                   
                ];
            });

        // Get count of approved coaches
        $approvedCoachesCount = $approvedCoaches->count();

        // Return the response
        return response()->json([
            'approved_coaches' => $approvedCoaches,
          
        ], 200);
    }
    public function getApprovedCoachesCount(Request $request)
    {
        // Get count of approved coaches
        $approvedCoachesCount = Coach::where('status', 'approved')->count();

        // Return the response
        return response()->json([
            'approved_coaches_count' => $approvedCoachesCount,
        ], 200);
    }

    public function getPendingCoachesCount(Request $request)
    {
        // Get count of pending coaches
        $pendingCoachesCount = Coach::where('status', 'pending')->count();

        // Return the response
        return response()->json([
            'pending_coaches_count' => $pendingCoachesCount,
        ], 200);
    }



      
    public function getAllTrainees(Request $request)
    {
        // Get all trainees with details from users and trainees tables
        $trainees = Trainee::with(['user' => function ($query) {
               $query->select('User_ID', 'full_name', 'email', 'linkedin_link', 'role_profile', 'photo', 'created_at', 'updated_at');
        }])
            ->withCount(['sessionsAsTrainee as completed_sessions_count' => function ($query) {
                $query->where('status', NewSession::STATUS_COMPLETED);
            }])
            ->get()
            ->map(function ($trainee) {
                return [
                    // From users table
                    'user_id' => $trainee->User_ID,
                    'full_name' => $trainee->user->full_name,
                    'email' => $trainee->user->email,
                    'photo' => $trainee->user->photo,
                    'completed_sessions' => $trainee->completed_sessions_count,
                  
                    // From trainees table
               
            
                   
                    'current_role' => $trainee->Current_Role,
                   
                    'years_of_professional_experience' => $trainee->Years_Of_Professional_Experience,
                    
                ];
            });

        // Return the response (without the count)
        return response()->json([
            'trainees' => $trainees,
        ], 200);
    }

    public function getTraineesCount(Request $request)
    {
        // Get count of trainees
        $traineesCount = Trainee::count();

        // Return the response
        return response()->json([
            'trainees_count' => $traineesCount,
        ], 200);
    }

 public function getSessionCompletionTrends(Request $request)
{
    $authAdmin = auth('admin-api')->user();
    if (!$authAdmin) {
        return response()->json(['message' => 'Unauthorized'], 401);
    }

    try {
        $trends = NewSession::select(
            DB::raw("DATE_FORMAT(created_at, '%b %Y') as month"),
            DB::raw("SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed"),
            DB::raw("SUM(CASE WHEN status = 'Canceled' THEN 1 ELSE 0 END) as canceled")
        )
        ->groupBy(DB::raw("DATE_FORMAT(created_at, '%b %Y')"))
        ->orderBy(DB::raw("DATE_FORMAT(created_at, '%b %Y')")) // تعديل هنا
        ->get()
        ->map(function ($trend) {
            return [
                'month' => $trend->month,
                'completed' => (int)$trend->completed,
                'canceled' => (int)$trend->canceled,
            ];
        });

        return response()->json([
            'message' => 'Session completion trends retrieved successfully',
            'trends' => $trends,
        ], 200);
    } catch (\Exception $e) {
        \Log::error('Failed to retrieve session completion trends', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'message' => 'Failed to retrieve session completion trends',
            'error' => $e->getMessage(),
        ], 500);
    }
}

    public function deleteUser(Request $request, $userId)
{
    // Check if the authenticated user is an admin
    $authAdmin = auth('admin-api')->user();
    if (!$authAdmin) {
        return response()->json(['message' => 'Unauthorized'], 401);
    }

    try {
        // Find the user
        $user = User::findOrFail($userId);

        // Start a transaction to ensure data consistency
        DB::beginTransaction();

        // Handle Coach-related data
        $coach = $user->coach;
        if ($coach) {
            // Delete coach skills and languages
            CoachSkill::where('coach_id', $userId)->forceDelete();
            CoachLanguage::where('coach_id', $userId)->forceDelete();

            // Delete coach services (pivot table: chooses)
            $coach->services()->detach();

            // Delete direct services
            Service::where('coach_id', $userId)->forceDelete();

            // Delete coach sessions
            NewSession::where('coach_id', $userId)->forceDelete();

            // Delete coach availability
            CoachAvailability::where('coach_id', $userId)->forceDelete();

            // Delete coach mentorship requests
            MentorshipRequest::where('coach_id', $userId)->forceDelete();

            // Delete coach record
            $coach->forceDelete();
        }

        // Handle Trainee-related data
        $trainee = $user->trainee;
        if ($trainee) {
            // Delete trainee preferred languages and areas of interest
            TraineePreferredLanguage::where('trainee_id', $userId)->forceDelete();
            TraineeAreaOfInterest::where('trainee_id', $userId)->forceDelete();

            // Delete trainee bookings (pivot table: books)
            $trainee->bookedSessions()->detach();

            // Delete trainee sessions
            NewSession::where('trainee_id', $userId)->forceDelete();

          

            // Delete trainee mentorship requests
            MentorshipRequest::where('trainee_id', $userId)->forceDelete();

            // Delete trainee record
            $trainee->forceDelete();
        }

        // Delete user-related data
        // Delete reviews (as coach or trainee)
        Review::where('coach_id', $userId)->orWhere('trainee_id', $userId)->forceDelete();

        // Delete bookings
        Book::where('trainee_id', $userId)->forceDelete();

     

      

        // Delete mentorship requests (polymorphic)
        MentorshipRequest::where('requestable_id', $userId)->where('requestable_type', User::class)->forceDelete();

        // Delete user photo if it exists
        if ($user->photo) {
            \Storage::disk('public')->delete($user->photo);
        }

        // Delete the user
        $user->forceDelete();

        // Commit the transaction
        DB::commit();

        return response()->json([
            'message' => 'User and associated data deleted successfully',
            'user_id' => $userId,
        ], 200);
    } catch (\Exception $e) {
        // Rollback the transaction on error
        DB::rollBack();

        \Log::error('Failed to delete user', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'message' => 'Failed to delete user',
            'error' => $e->getMessage(),
        ], 500);
    }
}


    public function searchTrainees(Request $request)
{
    // Check if the authenticated user is an admin
    $authAdmin = auth('admin-api')->user();
    if (!$authAdmin) {
        return response()->json(['message' => 'Unauthorized'], 401);
    }

    try {
        // Get the search query from the request
        $query = $request->input('query', '');

        // Search for trainees limited to full_name, email, and Current_Role
        $trainees = Trainee::with(['user' => function ($query) {
            $query->select('User_ID', 'full_name', 'email', 'photo', 'created_at', 'updated_at');
        }])
        ->whereHas('user', function ($q) use ($query) {
            $q->where('full_name', 'LIKE', "%{$query}%")
              ->orWhere('email', 'LIKE', "%{$query}%");
        })
        ->orWhere('Current_Role', 'LIKE', "%{$query}%")
        ->withCount(['sessionsAsTrainee as completed_sessions_count' => function ($query) {
            $query->where('status', NewSession::STATUS_COMPLETED);
        }])
        ->get()
        ->map(function ($trainee) {
            return [
                'user_id' => $trainee->User_ID,
                'full_name' => $trainee->user->full_name,
                'email' => $trainee->user->email,
                'photo' => $trainee->user->photo,
                'completed_sessions' => $trainee->completed_sessions_count,
                'current_role' => $trainee->Current_Role,
                'years_of_professional_experience' => $trainee->Years_Of_Professional_Experience,
            ];
        });

        return response()->json([
            'message' => 'Trainees retrieved successfully',
            'trainees' => $trainees,
        ], 200);
    } catch (\Exception $e) {
        \Log::error('Failed to search trainees', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'message' => 'Failed to search trainees',
            'error' => $e->getMessage(),
        ], 500);
    }
}

  /**
 * Search for coaches based on a single query string across full_name, email, title, and company.
 *
 * @param Request $request
 * @return \Illuminate\Http\JsonResponse
 */
public function searchCoaches(Request $request)
{
    // Check if the authenticated user is an admin
    $authAdmin = auth('admin-api')->user();
    if (!$authAdmin) {
        return response()->json(['message' => 'Unauthorized'], 401);
    }

    try {
        // Get the search query from the request
        $query = $request->input('query', '');

        // Build the query
        $coaches = Coach::with(['user' => function ($query) {
            $query->select('User_ID', 'full_name', 'email', 'photo', 'created_at', 'updated_at');
        }])
        ->whereHas('user', function ($q) use ($query) {
            $q->where('full_name', 'LIKE', "%{$query}%")
              ->orWhere('email', 'LIKE', "%{$query}%");
        })
        ->orWhere('Title', 'LIKE', "%{$query}%")
        ->orWhere('Company_or_School', 'LIKE', "%{$query}%")
        ->withCount(['sessions as completed_sessions_count' => function ($query) {
            $query->where('status', NewSession::STATUS_COMPLETED);
        }])
        ->get()
        ->map(function ($coach) {
            return [
                'user_id' => $coach->User_ID,
                'full_name' => $coach->user->full_name,
                'email' => $coach->user->email,
                'photo' => $coach->user->photo,
                'title' => $coach->Title,
                'company_or_school' => $coach->Company_or_School,
                'completed_sessions' => $coach->completed_sessions_count,
                'years_of_experience' => $coach->Years_Of_Experience,
            ];
        });

        return response()->json([
            'message' => 'Coaches retrieved successfully',
            'coaches' => $coaches,
        ], 200);
    } catch (\Exception $e) {
        \Log::error('Failed to search coaches', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'message' => 'Failed to search coaches',
            'error' => $e->getMessage(),
        ], 500);
    }
}
 public function getDashboardStats(Request $request)
{
    $authAdmin = auth('admin-api')->user();
    if (!$authAdmin) {
        return response()->json(['message' => 'Unauthorized'], 401);
    }

    try {
        // 1. Revenue (20%)
        $totalRevenue = Payment::where('payment_status', 'Completed')
            ->whereHas('service', function ($query) {
                $query->whereIn('service_type', ['Mentorship', 'Mock_Interview', 'Group_Mentorship'])
                      ->whereNull('deleted_at');
            })
            ->sum('amount');
        $revenue20Percent = $totalRevenue * 0.2;

        // 2. Number of Completed Sessions (across all valid services)
        $completedSessions = NewSession::where('status', NewSession::STATUS_COMPLETED)
            ->whereHas('service', function ($query) {
                $query->whereIn('service_type', ['Mentorship', 'Mock_Interview', 'Group_Mentorship'])
                      ->whereNull('deleted_at');
            })
            ->get();

        $totalCompletedSessionsCount = $completedSessions->count();

        // 3. Percentage of Completed Sessions by Service (based on new_sessions)
        $allServiceTypes = ['Mentorship', 'Mock_Interview', 'Group_Mentorship'];
        $sessionsByService = collect($allServiceTypes)->mapWithKeys(function ($serviceType) use ($completedSessions, $totalCompletedSessionsCount) {
            $count = $completedSessions->filter(function ($session) use ($serviceType) {
                return $session->service->service_type === $serviceType;
            })->count();
            $percentage = $totalCompletedSessionsCount > 0 ? ($count / $totalCompletedSessionsCount) * 100 : 0;
            return [$serviceType => round($percentage, 2)];
        });

        // 4. Get all completed payments with their services
        $completedPayments = Payment::where('payment_status', 'Completed')
            ->whereHas('service', function ($query) {
                $query->whereIn('service_type', ['Mentorship', 'Mock_Interview', 'Group_Mentorship'])
                      ->whereNull('deleted_at');
            })
            ->with('service')
            ->get();

        // 5. Revenue by Service (20% of each service's payments)
        $revenueByService = collect($allServiceTypes)->mapWithKeys(function ($serviceType) use ($completedPayments) {
            $paymentsForService = $completedPayments->filter(function ($payment) use ($serviceType) {
                return $payment->service && $payment->service->service_type === $serviceType;
            });
            $serviceRevenue = $paymentsForService->sum('amount') * 0.2;
            return [$serviceType => round($serviceRevenue, 2)];
        });

        $averageRating = Coach::has('reviews')->with('reviews')->get()->avg('average_rating');
        $totalUsers = User::count();

        return response()->json([
            'number_of_users' => $totalUsers,
            'revenue' => round($revenue20Percent, 2),
            'completed_sessions' => $totalCompletedSessionsCount,
            'sessions_percentage_by_service' => $sessionsByService,
            'revenue_by_service' => $revenueByService,
            'average_rating' => round($averageRating, 2),
        ], 200);
    } catch (\Exception $e) {
        \Log::error('Failed to retrieve dashboard stats', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'message' => 'Failed to retrieve dashboard stats',
            'error' => $e->getMessage(),
        ], 500);
    }
}
}
        
           
            
           
          

