<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MentorshipRequest;
use App\Models\NewSession;
use App\Models\Service;
use App\Models\GroupMentorship;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use App\Mail\SessionBooked;
use Stripe\Stripe;
use Stripe\PaymentIntent;

class PaymentController extends Controller
{
    public function initiatePayment(Request $request, $type, $id)
    {
        $user = Auth::user();

        if ($type === 'mentorship_request') {
            $mentorshipRequest = MentorshipRequest::findOrFail($id);

            if ($mentorshipRequest->trainee_id !== $user->User_ID) {
                return response()->json(['message' => 'This request does not belong to you.'], 403);
            }

            if ($mentorshipRequest->status !== 'accepted') {
                return response()->json(['message' => 'Request must be accepted to proceed with payment.'], 400);
            }

            // Check if there is a pending payment for this mentorship request
            $pendingPayment = \App\Models\PendingPayment::where('mentorship_request_id', $mentorshipRequest->id)->first();
            if (!$pendingPayment) {
                return response()->json(['message' => 'No pending payment found for this request.'], 400);
            }

            // Check if payment is still valid (not expired)
            if (Carbon::now()->gt($pendingPayment->payment_due_at)) {
                return response()->json(['message' => 'Payment due date has expired.'], 400);
            }

            // For MentorshipPlan, ensure all sessions are booked before payment
            if ($mentorshipRequest->requestable_type === \App\Models\MentorshipPlan::class) {
                $sessions = NewSession::where('mentorship_request_id', $mentorshipRequest->id)->get();
                if ($sessions->isEmpty()) {
                    return response()->json(['message' => 'You must book sessions before proceeding with payment.'], 400);
                }
                $sessionCount = $mentorshipRequest->requestable->session_count;
                if ($sessions->count() < $sessionCount) {
                    return response()->json(['message' => 'You must book all sessions for this Mentorship Plan before proceeding with payment.'], 400);
                }
            }
        } elseif ($type === 'service') {
            $session = NewSession::findOrFail($id);

            if ($session->trainee_id !== $user->User_ID) {
                return response()->json(['message' => 'This session does not belong to you.'], 403);
            }

            if ($session->status !== 'pending') {
                return response()->json(['message' => 'Session must be pending to proceed with payment.'], 400);
            }
        } else {
            return response()->json(['message' => 'Invalid payment type'], 400);
        }

        // Validate payment details
        $request->validate([
            'card_number' => 'required|string',
            'exp_month' => 'required|integer|between:1,12',
            'exp_year' => 'required|integer|min:' . now()->year,
            'cvc' => 'required|string|size:3',
        ]);

        Stripe::setApiKey(env('STRIPE_SECRET_KEY'));

        try {
            $price = $this->calculatePrice($type, $type === 'mentorship_request' ? $mentorshipRequest : $session);

            // Create a Payment Intent
            $paymentIntent = PaymentIntent::create([
                'amount' => $price * 100, // Amount in cents
                'currency' => 'egp',
                'payment_method_data' => [
                    'type' => 'card',
                    'card' => [
                        'number' => $request->card_number,
                        'exp_month' => $request->exp_month,
                        'exp_year' => $request->exp_year,
                        'cvc' => $request->cvc,
                    ],
                ],
                'metadata' => [
                    "{$type}_id" => $id,
                ],
                'return_url' => 'https://your-frontend-app.com/payment/return', // Replace with your Frontend URL
            ]);

            // Return the client secret to the frontend
            return response()->json([
                'client_secret' => $paymentIntent->client_secret,
                'payment_intent_id' => $paymentIntent->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Payment initiation failed', [
                "{$type}_id" => $id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Failed to initiate payment', 'error' => $e->getMessage()], 500);
        }
    }

    public function confirmPayment(Request $request, $type, $id)
    {
        Stripe::setApiKey(env('STRIPE_SECRET_KEY'));

        try {
            $paymentIntentId = $request->input('payment_intent_id');
            $paymentIntent = PaymentIntent::retrieve($paymentIntentId);

            if ($paymentIntent->status === 'succeeded') {
                return $this->completePayment($type, $id);
            } else {
                Log::warning('Payment confirmation failed', [
                    'payment_intent_id' => $paymentIntentId,
                    'status' => $paymentIntent->status,
                ]);
                return response()->json(['message' => 'Payment confirmation failed', 'status' => $paymentIntent->status], 400);
            }
        } catch (\Exception $e) {
            Log::error('Failed to confirm payment', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Failed to confirm payment', 'error' => $e->getMessage()], 500);
        }
    }

    public function completePayment($type, $id)
    {
        if ($type === 'mentorship_request') {
            $mentorshipRequest = MentorshipRequest::findOrFail($id);

            if ($mentorshipRequest->status !== 'accepted') {
                return response()->json(['message' => 'Request must be accepted to complete payment'], 400);
            }

            // Delete the pending payment record
            $pendingPayment = \App\Models\PendingPayment::where('mentorship_request_id', $mentorshipRequest->id)->first();
            if ($pendingPayment) {
                $pendingPayment->delete();
            }

            DB::beginTransaction();
            try {
                // If GroupMentorship, create session with predefined time
                if ($mentorshipRequest->requestable_type === \App\Models\GroupMentorship::class) {
                    $groupMentorship = $mentorshipRequest->requestable;

                    // Calculate the next occurrence of the session based on the day and start_time
                    $dayOfWeek = $groupMentorship->day;
                    $startTime = Carbon::parse($groupMentorship->start_time);
                    $nextSessionDate = Carbon::today()
                        ->next($dayOfWeek)
                        ->setTime($startTime->hour, $startTime->minute);

                    // Create a new session
                    NewSession::create([
                        'mentorship_request_id' => $mentorshipRequest->id,
                        'trainee_id' => $mentorshipRequest->trainee_id,
                        'coach_id' => $mentorshipRequest->coach_id,
                        'session_time' => $nextSessionDate,
                        'duration_minutes' => $groupMentorship->duration_minutes,
                        'status' => 'upcoming',
                    ]);

                    // Update current participants in Group Mentorship
                    $groupMentorship->increment('current_participants');
                    $groupMentorship->trainee_ids = array_merge($groupMentorship->trainee_ids ?? [], [$mentorshipRequest->trainee_id]);
                    $groupMentorship->save();

                    if ($groupMentorship->current_participants >= $groupMentorship->max_participants) {
                        $groupMentorship->is_active = false;
                        $groupMentorship->save();
                    }
                }

                // For MentorshipPlan, update session status to upcoming
                if ($mentorshipRequest->requestable_type === \App\Models\MentorshipPlan::class) {
                    NewSession::where('mentorship_request_id', $mentorshipRequest->id)
                        ->where('status', 'pending')
                        ->update(['status' => 'upcoming']);
                }

                // Send email to Trainee
                $trainee = User::find($mentorshipRequest->trainee_id);
                Mail::to($trainee->email)->send(new SessionBooked($mentorshipRequest));

                DB::commit();
                Log::info('Payment completed and sessions scheduled', [
                    'mentorship_request_id' => $id,
                ]);

                return response()->json([
                    'message' => 'Payment processed successfully',
                    'entity' => $mentorshipRequest->fresh(['requestable', 'trainee.user'])
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Failed to complete payment', [
                    'mentorship_request_id' => $id,
                    'error' => $e->getMessage(),
                ]);
                return response()->json(['error' => $e->getMessage()], 500);
            }
        } elseif ($type === 'service') {
            $session = NewSession::findOrFail($id);

            if ($session->status !== 'pending') {
                return response()->json(['message' => 'Session must be pending to complete payment'], 400);
            }

            DB::beginTransaction();
            try {
                $session->status = 'upcoming';
                $session->save();

                // Send email to Trainee
                $trainee = User::find($session->trainee_id);
                Mail::to($trainee->email)->send(new SessionBooked($session));

                DB::commit();
                Log::info('Payment completed and session scheduled', [
                    'session_id' => $id,
                ]);

                return response()->json([
                    'message' => 'Payment processed successfully',
                    'entity' => $session
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Failed to complete payment', [
                    'session_id' => $id,
                    'error' => $e->getMessage(),
                ]);
                return response()->json(['error' => $e->getMessage()], 500);
            }
        } else {
            return response()->json(['message' => 'Invalid payment type'], 400);
        }
    }

    private function calculatePrice($type, $entity)
    {
        $pricePerSession = 150; // EGP 150 per session (as seen in the card)

        if ($type === 'mentorship_request') {
            $request = $entity;
            if ($request->requestable_type === \App\Models\MentorshipPlan::class) {
                $mentorshipPlan = $request->requestable;
                return $pricePerSession * $mentorshipPlan->session_count;
            }
            return $pricePerSession; // For GroupMentorship
        } elseif ($type === 'service') {
            return $pricePerSession; // For regular services
        }

        return $pricePerSession; // Default
    }

    private function getServiceName($type, $entity)
    {
        if ($type === 'mentorship_request') {
            return $entity->requestable->title;
        } elseif ($type === 'service') {
            $session = $entity;
            $service = Service::find($session->service_id);
            return $service->title ?? 'Service Payment';
        }

        return "Service Payment";
    }
}
