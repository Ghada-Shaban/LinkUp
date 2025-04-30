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
use Illuminate\Support\Facades\Auth;
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
        // Validate the type
        if (!in_array($type, ['mentorship_request', 'session'])) {
            return response()->json(['message' => 'Invalid type'], 400);
        }

        if ($type === 'mentorship_request') {
            // Handle payment for mentorship_request (Mentorship Plan and Group Mentorship)
            $mentorshipRequest = MentorshipRequest::find($id);
            if (!$mentorshipRequest) {
                return response()->json(['message' => 'Mentorship request not found'], 404);
            }

            // Check if the user is authorized to make this payment
            if ($mentorshipRequest->trainee_id !== Auth::user()->User_ID) {
                return response()->json(['message' => 'This mentorship request does not belong to you.'], 403);
            }

            // Check if the mentorship request is accepted
            if ($mentorshipRequest->status !== 'accepted') {
                return response()->json(['message' => 'Request must be accepted to proceed with payment'], 400);
            }

            // Check if the mentorship request has already been paid
            $existingPayment = Payment::where('mentorship_request_id', $mentorshipRequest->id)->first();
            if ($existingPayment) {
                return response()->json(['message' => 'This mentorship request has already been paid'], 400);
            }

            // Check for a pending payment
            $pendingPayment = PendingPayment::where('mentorship_request_id', $mentorshipRequest->id)->first();
            if (!$pendingPayment) {
                return response()->json(['message' => 'No pending payment found for this request'], 404);
            }

            // Get the requestable entity (Service, GroupMentorship, or MentorshipPlan)
            $requestable = $mentorshipRequest->requestable;
            if (!$requestable) {
                return response()->json(['message' => 'Requestable entity not found'], 404);
            }

            $amount = 0;
            $currency = 'usd';
            $description = '';
            $serviceId = null;

            if ($mentorshipRequest->requestable_type === 'App\\Models\\Service') {
                $service = $requestable;
                $serviceId = $service->service_id;
                $priceEntry = Price::where('service_id', $service->service_id)->first();
                $amount = $priceEntry ? $priceEntry->price * 100 : 0;
                $description = "Payment for Service ID: {$requestable->id}";
            } elseif ($mentorshipRequest->requestable_type === 'App\\Models\\GroupMentorship') {
                $groupMentorship = $requestable;
                $serviceId = $groupMentorship->service_id;
                $priceEntry = Price::where('service_id', $groupMentorship->service_id)->first();
                $amount = $priceEntry ? $priceEntry->price * 100 : 0;
                $description = "Payment for Group Mentorship ID: {$requestable->id}";
            } elseif ($mentorshipRequest->requestable_type === 'App\\Models\\MentorshipPlan') {
                $mentorshipPlan = $requestable;
                $service = $mentorshipPlan->service;
                $serviceId = $service->service_id;
                $priceEntry = Price::where('service_id', $service->service_id)->first();
                if (!$priceEntry) {
                    return response()->json(['message' => 'Price not found for this mentorship plan'], 400);
                }

                // Get all sessions associated with this mentorship request
                $sessions = NewSession::where('mentorship_request_id', $mentorshipRequest->id)
                    ->where('status', 'Pending')
                    ->get();

                if ($sessions->isEmpty()) {
                    return response()->json(['message' => 'No pending sessions found for this mentorship plan'], 400);
                }

                // Calculate the total amount based on the number of sessions and price per session
                $amount = $sessions->count() * $priceEntry->price * 100; // Amount in cents
                $description = "Payment for Mentorship Plan ID: {$mentorshipPlan->id}";
            } else {
                return response()->json(['message' => 'Invalid requestable type'], 400);
            }

            if ($amount <= 0) {
                return response()->json(['message' => 'Invalid amount'], 400);
            }

            // Validate the payment method
            $request->validate([
                'payment_method_id' => 'required|string',
            ]);

            try {
                // Create a Payment Intent with Stripe
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

                // Step 1: Record the payment in the payments table
                $payment = Payment::create([
                    'mentorship_request_id' => $mentorshipRequest->id,
                    'amount' => $amount / 100,
                    'payment_method' => 'Credit_Card',
                    'payment_status' => 'Completed',
                    'date_time' => Carbon::now(),
                    'service_id' => $serviceId,
                ]);

                if (!$payment) {
                    Log::error('Failed to record payment in payments table', [
                        'mentorship_request_id' => $mentorshipRequest->id,
                    ]);
                    return response()->json(['message' => 'Payment succeeded but failed to record in payments table'], 500);
                }

                // Step 2: Handle the sessions and update new_sessions
                if ($mentorshipRequest->requestable_type === 'App\\Models\\GroupMentorship') {
                    $groupMentorship = $requestable;

                    // Check current_participants before proceeding
                    $traineeIds = $groupMentorship->trainee_ids ?? [];
                    $currentParticipants = count($traineeIds);
                    if (!in_array($mentorshipRequest->trainee_id, $traineeIds)) {
                        $currentParticipants++; // Simulate adding the new trainee
                    }

                    if ($currentParticipants > $groupMentorship->max_participants) {
                        Log::warning('Group Mentorship is full', [
                            'group_mentorship_id' => $groupMentorship->id,
                            'current_participants' => $currentParticipants,
                            'max_participants' => $groupMentorship->max_participants,
                        ]);
                        return response()->json(['message' => 'Group Mentorship is full'], 400);
                    }

                    // Parse the day and time, assuming the time in the database is in Africa/Cairo
                    $startDateTime = Carbon::parse($groupMentorship->day . ' ' . $groupMentorship->start_time, 'Africa/Cairo');
                    if ($startDateTime->lt(Carbon::now())) {
                        $startDateTime->addWeek();
                    }

                    // Convert to UTC before storing in the database
                    $startDateTime->setTimezone('UTC');

                    // Create 4 sessions, each one week apart
                    $sessionsCreated = [];
                    for ($i = 0; $i < 4; $i++) {
                        $sessionDateTime = $startDateTime->copy()->addWeeks($i);
                        $newSession = NewSession::create([
                            'coach_id' => $mentorshipRequest->coach_id,
                            'trainee_id' => $mentorshipRequest->trainee_id,
                            'date_time' => $sessionDateTime,
                            'duration' => $groupMentorship->duration_minutes,
                            'status' => NewSession::STATUS_SCHEDULED,
                            'payment_status' => 'Completed',
                            'meeting_link' => null,
                            'service_id' => $groupMentorship->service_id,
                            'mentorship_request_id' => $mentorshipRequest->id,
                        ]);

                        if (!$newSession) {
                            Log::error('Failed to create new session for Group Mentorship', [
                                'mentorship_request_id' => $mentorshipRequest->id,
                                'week' => $i + 1,
                            ]);
                            return response()->json(['message' => "Payment succeeded but failed to create session for week " . ($i + 1)], 500);
                        }

                        $sessionsCreated[] = $newSession;
                    }

                    // Update the trainee_ids and current_participants
                    if (!in_array($mentorshipRequest->trainee_id, $traineeIds)) {
                        $traineeIds[] = $mentorshipRequest->trainee_id;
                        $groupMentorship->trainee_ids = $traineeIds; // Laravel will handle encoding to JSON
                        $groupMentorship->current_participants = count($traineeIds);
                        $groupMentorship->save();
                    }
                } elseif ($mentorshipRequest->requestable_type === 'App\\Models\\MentorshipPlan') {
                    // Update the status of all sessions to 'Scheduled' and payment_status to 'Completed'
                    $updated = NewSession::where('mentorship_request_id', $mentorshipRequest->id)
                        ->where('status', 'Pending')
                        ->update([
                            'status' => 'Scheduled',
                            'payment_status' => 'Completed',
                            'updated_at' => Carbon::now(),
                        ]);

                    if ($updated === 0) {
                        Log::warning('No sessions were updated', [
                            'mentorship_request_id' => $mentorshipRequest->id,
                        ]);
                        return response()->json(['message' => 'Payment succeeded but no sessions were updated'], 500);
                    }
                }

                // Step 3: Delete the pending payment record
                $pendingPayment->delete();

                Log::info('Payment processed successfully for mentorship request', [
                    'mentorship_request_id' => $mentorshipRequest->id,
                    'amount' => $amount / 100,
                ]);

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
        } else {
            // Handle payment for regular session (Mock Interview, Mentorship Sessions)
            // Session data should be passed in the request
            $request->validate([
                'session_data' => 'required|array',
                'session_data.temp_session_id' => 'required|string',
                'session_data.trainee_id' => 'required|integer',
                'session_data.coach_id' => 'required|integer',
                'session_data.service_id' => 'required|integer',
                'session_data.date_time' => 'required|date',
                'session_data.duration' => 'required|integer',
                'payment_method_id' => 'required|string',
            ]);

            $sessionData = $request->input('session_data');

            // Verify the temp_session_id matches
            if ($sessionData['temp_session_id'] !== $id) {
                return response()->json(['message' => 'Invalid session ID'], 400);
            }

            // Verify the trainee_id matches the authenticated user
            if ($sessionData['trainee_id'] !== Auth::user()->User_ID) {
                return response()->json(['message' => 'You are not authorized to pay for this session'], 403);
            }

            // Fetch the service to get the price
            $service = Service::findOrFail($sessionData['service_id']);
            $priceEntry = Price::where('service_id', $service->service_id)->first();
            if (!$priceEntry) {
                return response()->json(['message' => 'Price not found for this service'], 400);
            }

            $amount = $priceEntry->price * 100; // Amount in cents
            $currency = 'usd';
            $description = "Payment for Session (Service ID: {$service->service_id})";

            if ($amount <= 0) {
                return response()->json(['message' => 'Invalid amount'], 400);
            }

            try {
                // Create a Payment Intent with Stripe
                $paymentIntent = PaymentIntent::create([
                    'amount' => $amount,
                    'currency' => $currency,
                    'description' => $description,
                    'metadata' => [
                        'temp_session_id' => $sessionData['temp_session_id'],
                        'trainee_id' => $sessionData['trainee_id'],
                        'coach_id' => $sessionData['coach_id'],
                        'service_id' => $sessionData['service_id'],
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
                        'temp_session_id' => $sessionData['temp_session_id'],
                    ]);
                    return response()->json(['message' => 'Payment failed: ' . $paymentIntent->status], 400);
                }

                // Step 1: Record the payment in the payments table
                $payment = Payment::create([
                    'mentorship_request_id' => null,
                    'amount' => $amount / 100,
                    'payment_method' => 'Credit_Card',
                    'payment_status' => 'Completed',
                    'date_time' => Carbon::now(),
                    'service_id' => $sessionData['service_id'],
                ]);

                if (!$payment) {
                    Log::error('Failed to record payment in payments table', [
                        'temp_session_id' => $sessionData['temp_session_id'],
                    ]);
                    return response()->json(['message' => 'Payment succeeded but failed to record in payments table'], 500);
                }

                // Step 2: Parse the date_time to respect the original time in Africa/Cairo
                $parsedDateTime = Carbon::parse($sessionData['date_time'], 'Africa/Cairo');
                // Convert to UTC before storing in the database
                $parsedDateTime->setTimezone('UTC');

                // Create the session in new_sessions
                $newSession = NewSession::create([
                    'coach_id' => $sessionData['coach_id'],
                    'trainee_id' => $sessionData['trainee_id'],
                    'date_time' => $parsedDateTime,
                    'duration' => $sessionData['duration'],
                    'status' => 'Scheduled',
                    'payment_status' => 'Completed',
                    'meeting_link' => null,
                    'service_id' => $sessionData['service_id'],
                    'mentorship_request_id' => null,
                ]);

                if (!$newSession) {
                    Log::error('Failed to create new session', [
                        'temp_session_id' => $sessionData['temp_session_id'],
                    ]);
                    return response()->json(['message' => 'Payment succeeded but failed to create session'], 500);
                }

                Log::info('Payment processed successfully for regular session', [
                    'temp_session_id' => $sessionData['temp_session_id'],
                    'amount' => $amount / 100,
                    'session_id' => $newSession->id,
                ]);

                return response()->json([
                    'message' => 'Payment processed successfully and session scheduled',
                    'session' => $newSession,
                ], 200);
            } catch (\Exception $e) {
                Log::error('Payment failed', [
                    'error' => $e->getMessage(),
                    'temp_session_id' => $sessionData['temp_session_id'],
                ]);
                return response()->json(['message' => 'Payment failed: ' . $e->getMessage()], 500);
            }
        }
    }

    public function confirmPayment(Request $request, $type, $id)
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }
}
