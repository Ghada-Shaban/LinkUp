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
        // 1. جلب جميع أنواع الخدمات
        $validServiceTypes = Service::pluck('service_type')->toArray();
        \Log::info('Valid service types', ['service_types' => $validServiceTypes]);

        // 2. Revenue (20%) - فقط للدفعات المرتبطة بخدمات
        $completedPayments = Payment::where('payment_status', 'Completed')->get();
        $linkedPayments = collect(); // لتخزين الدفعات المرتبطة بخدمات

        // 3. ربط الدفعات بخدمات
        foreach ($completedPayments as $payment) {
            \Log::info('Processing payment', [
                'payment_id' => $payment->payment_id,
                'mentorship_request_id' => $payment->mentorship_request_id,
                'amount' => $payment->amount,
                'date_time' => $payment->date_time
            ]);

            $session = null;
            $serviceType = null;
            $mentorshipType = null;
            $revenueKey = null;

            // الخطوة الأولى: التعامل مع Mentorship plan وGroup_Mentorship عبر mentorship_request_id
            if ($payment->mentorship_request_id) {
                $session = NewSession::where('mentorship_request_id', $payment->mentorship_request_id)
                    ->with(['service', 'service.mentorship'])
                    ->first();

                if ($session && $session->service && in_array($session->service->service_type, $validServiceTypes)) {
                    $serviceType = $session->service->service_type;
                    $mentorshipType = $session->service->mentorship ? $session->service->mentorship->mentorship_type : null;

                    // تحديد مفتاح الإيرادات بناءً على service_type وmentorship_type
                    if ($serviceType === 'Group_Mentorship') {
                        $revenueKey = 'Group_Mentorship';
                    } elseif ($serviceType === 'Mentorship' && $mentorshipType === 'Mentorship plan') {
                        $revenueKey = 'Mentorship plan';
                    } else {
                        \Log::warning('Unexpected service_type or mentorship_type for mentorship_request_id', [
                            'payment_id' => $payment->payment_id,
                            'mentorship_request_id' => $payment->mentorship_request_id,
                            'service_type' => $serviceType,
                            'mentorship_type' => $mentorshipType
                        ]);
                        continue; // استبعاد الدفعة
                    }

                    \Log::info('Payment linked via mentorship_request_id', [
                        'payment_id' => $payment->payment_id,
                        'mentorship_request_id' => $payment->mentorship_request_id,
                        'session_id' => $session->new_session_id,
                        'service_id' => $session->service_id,
                        'service_type' => $serviceType,
                        'mentorship_type' => $mentorshipType,
                        'revenue_key' => $revenueKey
                    ]);
                } else {
                    \Log::warning('No valid session or service for mentorship_request_id', [
                        'payment_id' => $payment->payment_id,
                        'mentorship_request_id' => $payment->mentorship_request_id,
                        'session_id' => $session ? $session->new_session_id : null
                    ]);
                    continue; // استبعاد الدفعة
                }
            } else {
                \Log::warning('Payment missing mentorship_request_id, checking for Mentorship session or Mock_Interview', [
                    'payment_id' => $payment->payment_id,
                    'amount' => $payment->amount,
                    'date_time' => $payment->date_time
                ]);

                // الخطوة الثانية: التعامل مع Mentorship session وMock_Interview
                // حاول ربط الدفعة بجلسة عبر date_time
                $session = NewSession::where('date_time', '>=', $payment->date_time->subHours(2))
                    ->where('date_time', '<=', $payment->date_time->addHours(2))
                    ->whereHas('service.mentorship', function ($query) {
                        $query->where('mentorship_type', 'Mentorship session');
                    })
                    ->first();

                if ($session && $session->service && $session->service->mentorship) {
                    $serviceType = $session->service->service_type;
                    $mentorshipType = $session->service->mentorship->mentorship_type;

                    // تحديد مفتاح الإيرادات
                    if ($serviceType === 'Mock_Interview') {
                        $revenueKey = 'Mock_Interview';
                    } elseif ($mentorshipType === 'Mentorship session') {
                        $revenueKey = 'Mentorship session';
                    } else {
                        \Log::warning('Unexpected service_type for Mentorship session', [
                            'payment_id' => $payment->payment_id,
                            'session_id' => $session->new_session_id,
                            'service_type' => $serviceType
                        ]);
                        continue; // استبعاد الدفعة
                    }

                    \Log::info('Payment linked via date_time', [
                        'payment_id' => $payment->payment_id,
                        'session_id' => $session->new_session_id,
                        'date_time' => $payment->date_time,
                        'service_id' => $session->service_id,
                        'service_type' => $serviceType,
                        'mentorship_type' => $mentorshipType,
                        'revenue_key' => $revenueKey
                    ]);
                } else {
                    // افتراض: الدفعة بدون mentorship_request_id هي Mentorship session أو Mock_Interview
                    $mentorshipSessionService = Service::whereHas('mentorship', function ($query) {
                        $query->where('mentorship_type', 'Mentorship session');
                    })->first();

                    if ($mentorshipSessionService) {
                        $serviceType = $mentorshipSessionService->service_type;
                        $mentorshipType = 'Mentorship session';
                        $revenueKey = ($serviceType === 'Mock_Interview') ? 'Mock_Interview' : 'Mentorship session';

                        \Log::info('Assigned service to payment without session', [
                            'payment_id' => $payment->payment_id,
                            'date_time' => $payment->date_time,
                            'service_id' => $mentorshipSessionService->service_id,
                            'service_type' => $serviceType,
                            'mentorship_type' => $mentorshipType,
                            'revenue_key' => $revenueKey
                        ]);
                    } else {
                        \Log::warning('No Mentorship session or Mock_Interview service found', [
                            'payment_id' => $payment->payment_id
                        ]);
                        continue; // استبعاد الدفعة
                    }
                }
            }

            // إضافة الدفعة إلى المجموعة مع revenue_key
            $payment->revenue_key = $revenueKey;
            $linkedPayments->push($payment);
        }

        // 4. حساب إجمالي الريفينيو للدفعات المرتبطة فقط
        $totalLinkedRevenue = $linkedPayments->sum('amount') * 0.2;

        // 5. Revenue by Service with Percentage
        $revenueByService = $linkedPayments
            ->groupBy('revenue_key')
            ->mapWithKeys(function ($payments, $revenueKey) use ($totalLinkedRevenue) {
                $serviceRevenue = $payments->sum('amount') * 0.2;
                $percentage = $totalLinkedRevenue > 0 ? ($serviceRevenue / $totalLinkedRevenue) * 100 : 0;
                return [
                    $revenueKey => [
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
            'revenue' => round($totalLinkedRevenue, 2),
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
        
           
            
           
          

