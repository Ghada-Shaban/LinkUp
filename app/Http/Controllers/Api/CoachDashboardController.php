<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NewSession;
use App\Models\Payment;
use App\Models\Review;
use App\Models\Service;
use App\Models\Mentorship;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CoachDashboardController extends Controller
{
    public function getCoachDashboardStats(Request $request)
    {
        $authCoach = Auth::user();
        if (!$authCoach || $authCoach->role_profile !== 'Coach') {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        try {
            // 1. Get all completed payments for this coach
            $completedPayments = Payment::where('payment_status', 'Completed')
                ->whereHas('service', function ($query) use ($authCoach) {
                    $query->where('coach_id', $authCoach->User_ID)
                          ->whereIn('service_type', ['Mentorship', 'Mock_Interview', 'Group_Mentorship'])
                          ->whereNull('deleted_at');
                })
                ->with('service.mentorship')
                ->get();

            // 2. Total Revenue (80%) - Only for this coach's completed payments
            $totalRevenue = $completedPayments->sum('amount');
            $revenue80Percent = round($totalRevenue * 0.8, 2);

            // 3. Get completed sessions for this coach that have a corresponding completed payment
            $completedSessions = NewSession::where('status', NewSession::STATUS_COMPLETED)
                ->where('coach_id', $authCoach->User_ID)
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

            // 4. Total Mentoring Time (Sum of durations of completed sessions in hours)
            $totalMentoringTime = $completedSessions->sum('duration') / 60; // Convert minutes to hours

            // 5. Average Rating for this coach
            $averageRating = Review::where('coach_id', $authCoach->User_ID)
                ->avg('rating');
            $averageRating = $averageRating ? round($averageRating, 2) : 0.0;

            // 6. Percentage of Sessions by Service based on revenue for this coach
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
                $serviceRevenue = $paymentsForService->sum('amount') * 0.8;
                return [$serviceType => $serviceRevenue];
            });
            $totalRevenue80 = $revenueByService->sum();
            $sessionsByService = $revenueByService->mapWithKeys(function ($revenue, $serviceType) use ($totalRevenue80) {
                $percentage = $totalRevenue80 > 0 ? ($revenue / $totalRevenue80) * 100 : 0;
                return [$serviceType => round($percentage, 2) . '%'];
            });

            // 7. Revenue by Service (80% of each service's payments for this coach)
            $revenueByService = collect($allServiceTypes)->mapWithKeys(function ($serviceType) use ($completedPayments) {
                $paymentsForService = $completedPayments->filter(function ($payment) use ($serviceType) {
                    if ($serviceType === 'Mentorship Session') {
                        return $payment->service->service_type === 'Mentorship' && $payment->service->mentorship && $payment->service->mentorship->mentorship_type === 'Mentorship session';
                    } elseif ($serviceType === 'Mentorship Plan') {
                        return $payment->service->service_type === 'Mentorship' && $payment->service->mentorship && $payment->service->mentorship->mentorship_type === 'Mentorship plan';
                    }
                    return $payment->service->service_type === $serviceType;
                });
                $serviceRevenue = $paymentsForService->sum('amount') * 0.8;
                return [$serviceType => round($serviceRevenue, 2)];
            });

            // Return the response
            return response()->json([
                'revenue' => $revenue80Percent,
                'completed_sessions' => $totalCompletedSessionsCount,
                'total_mentoring_time' => round($totalMentoringTime, 2),
                'average_rating' => $averageRating,
                'sessions_percentage_by_service' => $sessionsByService,
                'revenue_by_service' => $revenueByService,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve coach dashboard stats', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'message' => 'Failed to retrieve coach dashboard stats',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
