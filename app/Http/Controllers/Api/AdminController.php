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
use App\Models\NewSession;
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
  public function getDashboardStats(Request $request)
{
    try {
        // 1. جلب جميع أنواع الخدمات الموجودة
        $validServiceTypes = Service::pluck('service_type')->toArray();

        // 2. Revenue (20%) - فقط للدفعات المرتبطة بخدمات
        $completedPayments = Payment::where('payment_status', 'Completed')->get();
        $linkedPayments = collect(); // لتخزين الدفعات المرتبطة بخدمات

        // 3. ربط الدفعات بخدمات
        foreach ($completedPayments as $payment) {
            $session = null;

            // الخيار الأول: الربط عبر mentorship_request_id
            if ($payment->mentorship_request_id) {
                $session = NewSession::where('mentorship_request_id', $payment->mentorship_request_id)
                    ->with('service')
                    ->first();
            }

            // الخيار الثاني: إذا لم يكن هناك mentorship_request_id أو جلسة، حاول الربط عبر date_time
            if (!$session) {
                $session = NewSession::where('date_time', '>=', $payment->date_time->subHours(1))
                    ->where('date_time', '<=', $payment->date_time->addHours(1))
                    ->with('service')
                    ->first();

                if (!$session) {
                    \Log::warning('No session found for payment', [
                        'payment_id' => $payment->payment_id,
                        'mentorship_request_id' => $payment->mentorship_request_id,
                        'date_time' => $payment->date_time
                    ]);
                    continue; // استبعاد الدفعة
                }

                \Log::info('Payment linked via date_time', [
                    'payment_id' => $payment->payment_id,
                    'session_id' => $session->new_session_id,
                    'date_time' => $payment->date_time
                ]);
            }

            // التحقق من وجود خدمة
            if (!$session->service || !in_array($session->service->service_type, $validServiceTypes)) {
                \Log::warning('Session missing service or invalid service_type', [
                    'payment_id' => $payment->payment_id,
                    'session_id' => $session->new_session_id,
                    'mentorship_request_id' => $payment->mentorship_request_id,
                    'service_id' => $session->service_id ?? null
                ]);
                continue; // استبعاد الدفعة
            }

            // تسجيل الربط الناجح
            \Log::info('Payment linked to service', [
                'payment_id' => $payment->payment_id,
                'mentorship_request_id' => $payment->mentorship_request_id,
                'session_id' => $session->new_session_id,
                'service_id' => $session->service_id,
                'service_type' => $session->service->service_type
            ]);

            // إضافة الدفعة مع service_type إلى المجموعة
            $payment->service_type = $session->service->service_type;
            $linkedPayments->push($payment);
        }

        // 4. حساب إجمالي الريفينيو للدفعات المرتبطة فقط
        $totalAspectRevenue = $linkedPayments->sum('amount') * 0.2;

        // 5. Revenue by Service with Percentage (مع %)
        $revenueByService = $linkedPayments
            ->groupBy('service_type')
            ->mapWithKeys(function ($payments, $serviceType) use ($totalAspectRevenue) {
                $serviceRevenue = $payments->sum('amount') * 0.2;
                $percentage = $totalAspectRevenue > 0 ? ($serviceRevenue / $totalAspectRevenue) * 100 : 0;
                return [
                    $serviceType => [
                        'revenue' => round($serviceRevenue, 2),
                        'percentage' => number_format($percentage, 2) . '%' // إضافة %
                    ]
                ];
            });

        // 6. Number of Completed Sessions
        $completedSessions = NewSession::where('status', 'Completed')
            ->count();

        // 7. Average Rating
        $averageRating = Coach::has('reviews')
            ->with('reviews')
            ->get()
            ->avg('average_rating');

        // 8. Total Users
        $totalUsers = User::count();

        // Return the response
        return response()->json([
            'number_of_users' => $totalUsers,
            'revenue' => round($totalAspectRevenue, 2),
            'revenue_by_service' => $revenueByService,
            'completed_sessions' => $completedSessions,
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
}
        
           
            
           
          

