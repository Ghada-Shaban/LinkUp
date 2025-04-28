<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MentorshipRequest;
use App\Models\NewSession;
use App\Models\PendingPayment;
use App\Models\Payment;
use App\Models\GroupMentorship;
use App\Models\Service;
use App\Models\MentorshipPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use App\Models\Price;

class PaymentController extends Controller
{
    public function __construct()
    {
        Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
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

        $existingPayment = Payment::where('mentorship_request_id', $mentorshipRequest->id)->first();
        if ($existingPayment) {
            return response()->json(['message' => 'This mentorship request has already been paid'], 400);
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
            $service = $requestable;
            $priceEntry = Price::where('service_id', $service->service_id)->first();
            $amount = $priceEntry ? $priceEntry->price * 100 : 0;
            $description = "Payment for Service ID: {$requestable->id}";
        } elseif ($mentorshipRequest->requestable_type === 'App\\Models\\GroupMentorship') {
            $priceEntry = Price::where('service_id', $requestable->service_id)->first();
            $amount = $priceEntry ? $priceEntry->price * 100 : 0;
            $description = "Payment for Group Mentorship ID: {$requestable->id}";
        } elseif ($mentorshipRequest->requestable_type === 'App\\Models\\MentorshipPlan') {
            $service = $requestable->service;
            $priceEntry = Price::where('service_id', $service->service_id)->first();
            $amount = $priceEntry ? $priceEntry->price * 100 : 0;
            $description = "Payment for Mentorship Plan ID: {$requestable->id}";
        }

        if ($amount <= 0) {
            return response()->json(['message' => 'Invalid amount'], 400);
        }

        $request->validate([
            'payment_method_id' => 'required|string',
        ]);

        try {
            $paymentIntent = PaymentIntent::create([
                'amount' => $amount,
                'currency' => $currency,
                'description' => $description,
                'metadata' => [
                    'mentorship_request_id' => $mentorshipRequest->id,
                    'trainee_id' => $mentorshipRequest->trainee_id,
                    'coach_id' => $mentorshipRequest->coach_id,
                ],
                'payment_method' => $request->input('payment_method_id'),
                'confirm' => true,
                'automatic_payment_methods' => [
                    'enabled' => true,
                    'allow_redirects' => 'never',
                ],
            ]);

            if ($paymentIntent->status !== 'succeeded') {
                Log::error('Payment failed with status', [
                    'status' => $paymentIntent->status,
                    'mentorship_request_id' => $mentorshipRequest->id,
                ]);
                return response()->json(['message' => 'Payment failed: ' . $paymentIntent->status], 400);
            }

            // تسجيل الدفع في جدول payments
            $payment = Payment::create([
                'mentorship_request_id' => $mentorshipRequest->id,
                'amount' => $amount / 100,
                'payment_method' => 'Credit_Card', // لأنك بتستخدمي Stripe
                'payment_status' => 'Completed',
                'date_time' => Carbon::now(),
            ]);

            if ($mentorshipRequest->requestable_type === 'App\\Models\\GroupMentorship') {
                $groupMentorship = $requestable;
                $startDateTime = Carbon::parse($groupMentorship->day . ' ' . $groupMentorship->start_time);
                if ($startDateTime->lt(Carbon::now())) {
                    $startDateTime->addWeek();
                }

                $newSession = NewSession::create([
                    'coach_id' => $mentorshipRequest->coach_id,
                    'trainee_id' => $mentorshipRequest->trainee_id,
                    'date_time' => $startDateTime,
                    'duration' => $groupMentorship->duration_minutes,
                    'status' => NewSession::STATUS_SCHEDULED,
                    'payment_status' => 'Completed',
                    'meeting_link' => null, // ممكن تضيفي رابط الاجتماع لو عندك
                    'service_id' => $requestable->service_id,
                    'mentorship_request_id' => $mentorshipRequest->id,
                ]);

                if (!$newSession) {
                    Log::error('Failed to create new session', [
                        'mentorship_request_id' => $mentorshipRequest->id,
                    ]);
                    return response()->json(['message' => 'Payment succeeded but failed to create session'], 500);
                }
            }

            // لو كل حاجة اكتملت بنجاح، امسح السجل من pending_payments
            $pendingPayment->delete();

            return response()->json([
                'message' => 'Payment processed successfully',
                'entity' => $mentorshipRequest
            ], 200);
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
