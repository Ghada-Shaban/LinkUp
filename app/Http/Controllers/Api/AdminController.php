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
use Illuminate\Support\Facades\Mail;
use App\Mail\CoachAccepted;
use App\Mail\CoachRejected;

class AdminController extends Controller
{
   
public function getPendingCoachRequests(Request $request)
{
    
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
        return response()->json([
            'message' => 'Failed to retrieve pending coach requests',
            'error' => $e->getMessage(),
        ], 500);
    }
}

public function handleCoachRequest(Request $request, $coachId)
{

    $authAdmin = auth('admin-api')->user();
    if (!$authAdmin) {
        return response()->json(['message' => 'Unauthorized'], 401);
    }

    $validated = $request->validate([
        'action' => 'required|in:approve,reject',
    ]);

    try {
       
        $coach = Coach::with('user')->findOrFail($coachId);
        if ($coach->status !== Coach::STATUS_PENDING) {
            return response()->json(['message' => 'Request has already been processed'], 400);
        }
       
        $user = $coach->user;
        
        if ($validated['action'] === 'approve') {
            $coach->status = Coach::STATUS_APPROVED;
            $coach->admin_id = $authAdmin->id; 
            $message = 'Coach registration request approved successfully';

         if ($user && $user->email) {
                try {
                    Mail::to($user->email)->send(new CoachAccepted($coach, $user));
                } catch (\Exception $e) {   
                }
            }
        } else {
            $coach->status = Coach::STATUS_REJECTED;
            $message = 'Coach registration request rejected';

       
          
          if ($user && $user->email) {
                try {
                    Mail::to($user->email)->send(new CoachRejected($coach, $user));
                } catch (\Exception $e) {
                }
            }
            CoachLanguage::where('coach_id', $coach->User_ID)->forceDelete();
            CoachSkill::where('coach_id', $coach->User_ID)->forceDelete();
            if ($user && $user->photo) {
                \Storage::disk('public')->delete($user->photo);
            }
            $coach->forceDelete();
            User::where('User_ID', $coach->User_ID)->forceDelete();
        }
        if ($validated['action'] === 'approve') {
            $coach->save();
        }

        return response()->json([
            'message' => $message,
            'coach_id' => $coachId,
            'status' => $coach->status,
        ], 200);
    } catch (\Exception $e) {
        return response()->json([
            'message' => 'Failed to handle coach registration request',
        ], 500);
    }
}

    
public function getTopCoaches(Request $request)
{
    
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
        ->take(5)
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
                'user_id' => $coach->User_ID,
                'full_name' => $coach->user->full_name,
                'email' => $coach->user->email,
                'photo' => $coach->user->photo,
               'completed_sessions' => $coach->completed_sessions_count,
                'title' => $coach->Title,
                'years_of_experience' => $coach->Years_Of_Experience,
                'months_of_experience' => $coach->Months_Of_Experience,
            ];
        });
    $approvedCoachesCount = $approvedCoaches->count();
    return response()->json([
        'approved_coaches' => $approvedCoaches,
    ], 200);
}

    
public function getApprovedCoachesCount(Request $request)
{

    $approvedCoachesCount = Coach::where('status', 'approved')->count();
    return response()->json([
        'approved_coaches_count' => $approvedCoachesCount,
    ], 200);
}

    
public function getPendingCoachesCount(Request $request)
{
    $pendingCoachesCount = Coach::where('status', 'pending')->count();
    return response()->json([
        'pending_coaches_count' => $pendingCoachesCount,
    ], 200);
}

    
public function getAllTrainees(Request $request)
{
    $trainees = Trainee::with(['user' => function ($query) {
           $query->select('User_ID', 'full_name', 'email', 'linkedin_link', 'role_profile', 'photo', 'created_at', 'updated_at');
    }])
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
        'trainees' => $trainees,
    ], 200);
}

public function getTraineesCount(Request $request)
{
    $traineesCount = Trainee::count();
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
            DB::raw("SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled")
        )
        ->groupBy(DB::raw("DATE_FORMAT(created_at, '%b %Y')"))
        ->orderBy(DB::raw("DATE_FORMAT(created_at, '%b %Y')"))
        ->get()
        ->map(function ($trend) {
            return [
                'month' => $trend->month,
                'completed' => (int)$trend->completed,
                'cancelled' => (int)$trend->cancelled,
            ];
        });

        return response()->json([
            'message' => 'Session completion trends retrieved successfully',
            'trends' => $trends,
        ], 200);
    } catch (\Exception $e) {
        return response()->json([
            'message' => 'Failed to retrieve session completion trends',
            'error' => $e->getMessage(),
        ], 500);
    }
}

public function deleteUser(Request $request, $userId)
{
    $authAdmin = auth('admin-api')->user();
    if (!$authAdmin) {
        return response()->json(['message' => 'Unauthorized'], 401);
    }

    try {
        $user = User::findOrFail($userId);
        DB::beginTransaction();
        $coach = $user->coach;
        if ($coach) {
            CoachSkill::where('coach_id', $userId)->forceDelete();
            CoachLanguage::where('coach_id', $userId)->forceDelete();
            $coach->services()->detach();
            Service::where('coach_id', $userId)->forceDelete();
            NewSession::where('coach_id', $userId)->forceDelete();
            CoachAvailability::where('coach_id', $userId)->forceDelete();
            MentorshipRequest::where('coach_id', $userId)->forceDelete();
            $coach->forceDelete();
        }
        $trainee = $user->trainee;
        if ($trainee) {
            TraineePreferredLanguage::where('trainee_id', $userId)->forceDelete();
            TraineeAreaOfInterest::where('trainee_id', $userId)->forceDelete();
            $trainee->bookedSessions()->detach();
            NewSession::where('trainee_id', $userId)->forceDelete();
            MentorshipRequest::where('trainee_id', $userId)->forceDelete();
            $trainee->forceDelete();
        }
        Review::where('coach_id', $userId)->orWhere('trainee_id', $userId)->forceDelete();
        Book::where('trainee_id', $userId)->forceDelete();
        MentorshipRequest::where('requestable_id', $userId)->where('requestable_type', User::class)->forceDelete();
        if ($user->photo) {
            \Storage::disk('public')->delete($user->photo);
        }
        $user->forceDelete();
        DB::commit();

        return response()->json([
            'message' => 'User and associated data deleted successfully',
            'user_id' => $userId,
        ], 200);
    } catch (\Exception $e) {
        // Rollback the transaction on error
        DB::rollBack();
        return response()->json([
            'message' => 'Failed to delete user',
            'error' => $e->getMessage(),
        ], 500);
    }
}

    
public function searchTrainees(Request $request)
{
    $authAdmin = auth('admin-api')->user();
    if (!$authAdmin) {
        return response()->json(['message' => 'Unauthorized'], 401);
    }

    try {
        $query = $request->input('query', '');
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
        return response()->json([
            'message' => 'Failed to search trainees',
            'error' => $e->getMessage(),
        ], 500);
    }
}

    
public function searchCoaches(Request $request)
{
    $authAdmin = auth('admin-api')->user();
    if (!$authAdmin) {
        return response()->json(['message' => 'Unauthorized'], 401);
    }

    try {
        $query = $request->input('query', '');
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
        $completedPayments = Payment::where('payment_status', 'Completed')
            ->whereHas('service', function ($query) {
                $query->whereIn('service_type', ['Mentorship', 'Mock_Interview', 'Group_Mentorship'])
                      ->whereNull('deleted_at');
            })
            ->with('service.mentorship')
            ->get();

        $totalRevenue = $completedPayments->sum('amount');
        $revenue26Percent = round($totalRevenue * 0.26, 2);
        $completedSessions = NewSession::where('status', NewSession::STATUS_COMPLETED)
            ->whereHas('service', function ($query) {
                $query->whereIn('service_type', ['Mentorship', 'Mock_Interview', 'Group_Mentorship'])
                      ->whereNull('deleted_at');
            })
            ->whereHas('service.payments', function ($query) {
                $query->where('payment_status', 'Completed');
            })
            ->with('service.mentorship')
            ->get();
        $totalCompletedSessionsCount = $completedSessions->count();
        $allServiceTypes = ['Mentorship Session', 'Mentorship Plan', 'Mock_Interview', 'Group_Mentorship'];
        $revenueByService = collect($allServiceTypes)->mapWithKeys(function ($serviceType) use ($completedPayments) {
            $paymentsForService = $completedPayments->filter(function ($payment) use ($serviceType) {
                if ($serviceType === 'Mentorship Session') {
                    return $payment->service->service_type === 'Mentorship' && $payment->service->mentorship && $payment->service->mentorship->mentorship_type === 'Mentorship session';
                } elseif ($serviceType === 'Mentorship Plan') {
                    return $payment->service->service_type === 'Mentorship' && $payment->service->mentorship && $payment->service->mentorship->mentorship_type === 'Mentorship plan';
                }
                return $payment->service->service_type === $serviceType;
            });
            $serviceRevenue = $paymentsForService->sum('amount') * 0.26;
            return [$serviceType => $serviceRevenue];
        });
        $totalRevenue26 = $revenueByService->sum();
        $sessionsByService = $revenueByService->mapWithKeys(function ($revenue, $serviceType) use ($totalRevenue26) {
            $percentage = $totalRevenue26 > 0 ? ($revenue / $totalRevenue26) * 100 : 0;
            return [$serviceType => round($percentage, 2) . '%'];
        });
        
        $revenueByService = collect($allServiceTypes)->mapWithKeys(function ($serviceType) use ($completedPayments) {
            $paymentsForService = $completedPayments->filter(function ($payment) use ($serviceType) {
                if ($serviceType === 'Mentorship Session') {
                    return $payment->service->service_type === 'Mentorship' && $payment->service->mentorship && $payment->service->mentorship->mentorship_type === 'Mentorship session';
                } elseif ($serviceType === 'Mentorship Plan') {
                    return $payment->service->service_type === 'Mentorship' && $payment->service->mentorship && $payment->service->mentorship->mentorship_type === 'Mentorship plan';
                }
                return $payment->service->service_type === $serviceType;
            });
            $serviceRevenue = $paymentsForService->sum('amount') * 0.26;
            return [$serviceType => round($serviceRevenue, 2)];
        });

        $averageRating = Coach::has('reviews')
            ->withAvg('reviews', 'rating')
            ->get()
            ->avg('reviews_avg_rating');

        $totalUsers = User::count();

        return response()->json([
            'number_of_users' => $totalUsers,
            'revenue' => $revenue26Percent,
            'sessions_percentage_by_service' => $sessionsByService,
            'revenue_by_service' => $revenueByService,
            'completed_sessions' => $totalCompletedSessionsCount,
            'average_rating' => round($averageRating, 2),
        ], 200);
    } catch (\Exception $e) {
        return response()->json([
            'message' => 'Failed to retrieve dashboard stats',
            'error' => $e->getMessage(),
        ], 500);
    }
}
}
