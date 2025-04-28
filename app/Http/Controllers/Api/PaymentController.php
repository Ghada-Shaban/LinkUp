<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GroupMentorship;
use App\Models\MentorshipRequest;
use App\Models\NewSession;
use App\Models\PendingPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\PaymentIntent;

class PaymentController extends Controller
{
    public function __construct()
    {
        // Set Stripe API key
        Stripe::setApiKey(env('STRIPE_SECRET'));
    }

    public function initiatePayment(Request $request, $type, $id)
    {
        $request->validate([
            'payment_method_id' => 'required|string',
        ]);

        $user = Auth::user();
        $amount = 0;
        $description = '';
        $metadata = [];

        DB::beginTransaction();

        try {
            if ($type === 'mentorship_request') {
                // Handle Mentorship Request payment
                $mentorshipRequest = MentorshipRequest::findOrFail($id);

                // Check if the mentorship request belongs to the authenticated trainee
                if ($mentorshipRequest->trainee_id !== $user->User_ID) {
                    return response()->json(['message' => 'This mentorship request does not belong to you.'], 403);
                }

                // Check if the mentorship request is accepted
                if ($mentorshipRequest->status !== 'accepted') {
                    return response()->json(['message' => 'Mentorship request must be accepted to proceed with payment.'], 400);
                }

                // Get sessions associated with this mentorship request
                $sessions = NewSession::where('mentorship_request_id', $id)
                    ->where('status', 'pending')
                    ->get();

                if ($sessions->isEmpty()) {
                    return response()->json(['message' => 'No pending sessions found for this mentorship request.'], 400);
                }

                // Calculate total amount based on sessions
                $sessionCount = $sessions->count();
                $pricePerSession = $mentorshipRequest->requestable->price_per_session;
                $amount = $sessionCount * $pricePerSession;

                $description = "Payment for Mentorship Request #{$id}";
                $metadata = [
                    'type' => 'mentorship_request',
                    'mentorship_request_id' => $id,
                    'trainee_id' => $user->User_ID,
                    'coach_id' => $mentorshipRequest->coach_id,
                ];

                // Check if there's a pending payment entry
                $pendingPayment = PendingPayment::where('mentorship_request_id', $id)
                    ->where('trainee_id', $user->User_ID)
                    ->first();

                if (!$pendingPayment) {
                    return response()->json(['message' => 'No pending payment found for this mentorship request.'], 400);
                }

            } elseif ($type === 'group_mentorship') {
                // Handle Group Mentorship payment
                $groupMentorship = GroupMentorship::findOrFail($id);

                // Check if the group mentorship is still active and has capacity
                if ($groupMentorship->current_participants >= $groupMentorship->max_participants) {
                    return response()->json(['message' => 'Group mentorship is already full.'], 400);
                }

                // Check if the user is already a participant
                $isParticipant = DB::table('group_mentorship_participants')
                    ->where('group_mentorship_id', $id)
                    ->where('trainee_id', $user->User_ID)
                    ->exists();

                if ($isParticipant) {
                    return response()->json(['message' => 'You are already a participant in this group mentorship.'], 400);
                }

                $amount = $groupMentorship->price;
                $description = "Payment for Group Mentorship #{$id}";
                $metadata = [
                    'type' => 'group_mentorship',
                    'group_mentorship_id' => $id,
                    'trainee_id' => $user->User_ID,
                    'coach_id' => $groupMentorship->coach_id,
                ];

            } else {
                return response()->json(['message' => 'Invalid payment type'], 400);
            }

            // Create Payment Intent with Stripe
            $paymentIntent = PaymentIntent::create([
                'amount' => $amount * 100, // Amount in cents
                'currency' => 'usd',
                'payment_method' => $request->payment_method_id,
                'description' => $description,
                'metadata' => $metadata,
                'confirmation_method' => 'manual',
                'confirm' => true,
            ]);

            if ($paymentIntent->status === 'succeeded') {
                if ($type === 'mentorship_request') {
                    // Update sessions status to 'upcoming'
                    foreach ($sessions as $session) {
                        $session->update([
                            'status' => 'upcoming',
                            'payment_status' => 'Completed',
                        ]);
                    }

                    // Remove the pending payment entry
                    $pendingPayment->delete();

                } elseif ($type === 'group_mentorship') {
                    // Add the trainee as a participant
                    DB::table('group_mentorship_participants')->insert([
                        'group_mentorship_id' => $id,
                        'trainee_id' => $user->User_ID,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Increment the current participants count
                    $groupMentorship->increment('current_participants');
                }

                DB::commit();
                Log::info('Payment processed successfully', [
                    'type' => $type,
                    'id' => $id,
                    'trainee_id' => $user->User_ID,
                    'amount' => $amount,
                ]);

                return response()->json(['message' => 'Payment processed successfully.'], 200);
            } else {
                DB::rollBack();
                return response()->json(['message' => 'Payment failed.'], 400);
            }

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Payment initiation failed', [
                'type' => $type,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Payment initiation failed: ' . $e->getMessage()], 500);
        }
    }

    public function confirmPayment(Request $request, $type, $id)
    {
        // This method can be used if you need to confirm a payment intent separately
        // For now, we're confirming directly in initiatePayment
        return response()->json(['message' => 'Payment confirmation not required.'], 200);
    }
}
