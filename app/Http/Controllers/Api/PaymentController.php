<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MentorshipRequest;
use App\Models\NewSession;
use App\Models\PendingPayment;
use App\Models\GroupMentorship;
use App\Models\Service;
use App\Models\MentorshipPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Stripe\Stripe;
use Stripe\PaymentIntent;

class PaymentController extends Controller
{
    public function __construct()
    {
        Stripe::setApiKey(env('STRIPE_KEY')); // الـ Secret Key من ملف .env
    }

    public function initiatePayment(Request $request, $type, $id)
    {
        if ($type !== 'mentorship_request') {
            return response()->json(['message' => 'Invalid type'], 400);
        }

        $mentorshipRequest = MentorshipRequest::find($id);
        if (!$mentorshipRequest) {
            return response()->json(['message' => 'Mentorship request not found'], 404);
        }

        if ($mentorshipRequest->status !== 'accepted') {
            return response()->json(['message' => 'Request must be accepted to proceed with payment'], 400);
        }

        $pendingPayment = PendingPayment::where('mentorship_request_id', $mentorshipRequest->id)->first();
        if (!$pendingPayment) {
            return response()->json(['message' => 'No pending payment found for this request'], 404);
        }

        $requestable = $mentorshipRequest->requestable;
        if (!$requestable) {
            return response()->json(['message' => 'Requestable entity not found'], 404);
        }

        $amount = 0;
        $currency = 'usd';
        $description = '';

        if ($mentorshipRequest->requestable_type === 'App\\Models\\Service') {
            $amount = $requestable->price * 100; // بالسنت
            $description = "Payment for Service ID: {$requestable->id}";
        } elseif ($mentorshipRequest->requestable_type === 'App\\Models\\GroupMentorship') {
            $amount = $requestable->price * 100;
            $description = "Payment for Group Mentorship ID: {$requestable->id}";
        } elseif ($mentorshipRequest->requestable_type === 'App\\Models\\MentorshipPlan') {
            $amount = $requestable->price * 100;
            $description = "Payment for Mentorship Plan ID: {$requestable->id}";
        }

        if ($amount <= 0) {
            return response()->json(['message' => 'Invalid amount'], 400);
        }

        try {
            // إنشاء PaymentIntent
            $paymentIntent = PaymentIntent::create([
                'amount' => $amount,
                'currency' => $currency,
                'description' => $description,
                'metadata' => [
                    'mentorship_request_id' => $mentorshipRequest->id,
                    'trainee_id' => $mentorshipRequest->trainee_id,
                    'coach_id' => $mentorshipRequest->coach_id,
                ],
                'payment_method_data' => [
                    'type' => 'card',
                    'card' => [
                        'number' => '4242424242424242', // بطاقة اختبار
                        'exp_month' => 12,
                        'exp_year' => 2026,
                        'cvc' => '123',
                    ],
                ],
                'confirm' => true, // تأكيد الدفع مباشرة
                'automatic_payment_methods' => [
                    'enabled' => true,
                    'allow_redirects' => 'never', // منع الـ Redirects (لأننا في الـ Backend)
                ],
            ]);

            // لو الدفع نجح
            if ($paymentIntent->status === 'succeeded') {
                $pendingPayment->delete();

                // إنشاء جلسة جديدة
                if ($mentorshipRequest->requestable_type === 'App\\Models\\GroupMentorship') {
                    $groupMentorship = $requestable;
                    $startDateTime = Carbon::parse($groupMentorship->day . ' ' . $groupMentorship->start_time);
                    if ($startDateTime->lt(Carbon::now())) {
                        $startDateTime->addWeek();
                    }

                    NewSession::create([
                        'mentorship_request_id' => $mentorshipRequest->id,
                        'trainee_id' => $mentorshipRequest->trainee_id,
                        'coach_id' => $mentorshipRequest->coach_id,
                        'session_time' => $startDateTime,
                        'duration' => $groupMentorship->duration_minutes,
                        'status' => 'upcoming',
                    ]);
                }

                return response()->json([
                    'message' => 'Payment processed successfully',
                    'entity' => $mentorshipRequest
                ], 200);
            } else {
                return response()->json(['message' => 'Payment failed: ' . $paymentIntent->status], 400);
            }
        } catch (\Exception $e) {
            Log::error('Payment failed', [
                'error' => $e->getMessage(),
                'mentorship_request_id' => $mentorshipRequest->id,
            ]);
            return response()->json(['message' => 'Payment failed: ' . $e->getMessage()], 500);
        }
    }

    public function confirmPayment(Request $request, $type, $id)
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }
}
