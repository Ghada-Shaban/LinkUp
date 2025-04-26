<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MentorshipRequest;
use App\Models\NewSession;
use App\Models\Service;
use App\Models\GroupMentorship;
use App\Models\User;
use Illuminate\Http\Request;
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
        if ($type === 'mentorship_request') {
            $entity = MentorshipRequest::findOrFail($id);

            if ($entity->status !== 'accepted') {
                return response()->json(['message' => 'Request must be accepted to proceed with payment'], 400);
            }
        } elseif ($type === 'service') {
            $entity = NewSession::findOrFail($id);

            if ($entity->status !== 'pending') {
                return response()->json(['message' => 'Session must be pending to proceed with payment'], 400);
            }
        } else {
            return response()->json(['message' => 'Invalid payment type'], 400);
        }

        // Validate payment details (for testing purposes only)
        $request->validate([
            'card_number' => 'required|string',
            'exp_month' => 'required|integer|between:1,12',
            'exp_year' => 'required|integer',
            'cvc' => 'required|string',
        ]);

        Stripe::setApiKey(env('STRIPE_SECRET_KEY'));

        try {
            $price = $this->calculatePrice($type, $entity);

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
                'confirm' => true,
                'metadata' => [
                    "{$type}_id" => $id,
                ],
            ]);

            if ($paymentIntent->status === 'succeeded') {
                // Payment successful, proceed to complete the request
                return $this->completePayment($type, $id);
            } else {
                Log::warning('Payment failed', [
                    "{$type}_id" => $id,
                    'payment_intent_id' => $paymentIntent->id,
                    'status' => $paymentIntent->status,
                ]);
                return response()->json(['message' => 'Payment failed'], 400);
            }
        } catch (\Exception $e) {
            Log::error('Failed to process payment', [
                "{$type}_id" => $id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function completePayment($type, $id)
    {
        if ($type === 'mentorship_request') {
            $mentorshipRequest = MentorshipRequest::findOrFail($id);

            if ($mentorshipRequest->status !== 'accepted') {
                return response()->json(['message' => 'Request must be accepted to complete payment'], 400);
            }

            DB::beginTransaction();
            try {
                // If GroupMentorship, create session with predefined time
                if ($mentorshipRequest->requestable_type === \App\Models\GroupMentorship::class) {
                    $groupMentorship = $mentorshipRequest->requestable;
                    $groupMentorship->addTrainee($mentorshipRequest->trainee_id);

                    NewSession::create([
                        'mentorship_request_id' => $mentorshipRequest->id,
                        'trainee_id' => $mentorshipRequest->trainee_id,
                        'coach_id' => $mentorshipRequest->coach_id,
                        'session_time' => Carbon::parse($groupMentorship->day . ' ' . $groupMentorship->start_time)->next($groupMentorship->day),
                        'duration_minutes' => $groupMentorship->duration_minutes,
                        'status' => 'upcoming',
                    ]);
                }

                // Send email to Trainee
                $trainee = User::find($mentorshipRequest->trainee_id);
                Mail::to($trainee->email)->send(new SessionBooked($mentorshipRequest));

                DB::commit();
                Log::info('Payment completed and sessions scheduled', [
                    'mentorship_request_id' => $id,
                ]);

                if ($mentorshipRequest->requestable_type === \App\Models\MentorshipPlan::class) {
                    return response()->json([
                        'message' => 'Payment completed successfully. Please schedule your sessions using /mentorship-requests/' . $id . '/schedule',
                        'request' => $mentorshipRequest->fresh(['requestable', 'trainee.user'])
                    ]);
                }

                return response()->json([
                    'message' => 'Payment completed successfully. Session scheduled.',
                    'request' => $mentorshipRequest->fresh(['requestable', 'trainee.user'])
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
                    'message' => 'Payment completed successfully. Session scheduled.',
                    'session' => $session
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
