<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MentorshipRequest;
use App\Models\CoachAvailability;
use App\Models\Service;
use App\Models\MentorshipPlan;
use App\Models\GroupMentorship;
use App\Models\NewSession;
use App\Models\User;
use App\Models\PendingPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use App\Mail\NewMentorshipRequest;
use App\Mail\PaymentReminder;
use App\Mail\SessionBooked;
use Stripe\Stripe;
use Stripe\Checkout\Session as StripeSession;

class MentorshipRequestController extends Controller
{
    public function requestMentorship(Request $request)
    {
        $request->validate([
            'service_id' => 'required|exists:services,service_id',
            'service_type' => 'required|in:MentorshipPlan,GroupMentorship',
        ]);

        $typeMap = [
            'MentorshipPlan' => \App\Models\MentorshipPlan::class,
            'GroupMentorship' => \App\Models\GroupMentorship::class,
        ];

        $serviceTypeInput = $request->input('service_type');
        $modelClass = $typeMap[$serviceTypeInput];
        $serviceId = $request->input('service_id');

        // Get the actual service
        $service = $modelClass::findOrFail($serviceId);

        // Pull the coach from the service relationship
        $coachId = $service->service->coach_id;

        // For GroupMentorship, check max_participants
        if ($serviceTypeInput === 'GroupMentorship') {
            if ($service->current_participants >= $service->max_participants) {
                return response()->json(['message' => 'Group Mentorship is already full'], 400);
            }
        }

        DB::beginTransaction();
        try {
            $mentorshipRequest = MentorshipRequest::create([
                'requestable_id' => $serviceId,
                'requestable_type' => $modelClass,
                'trainee_id' => Auth::user()->User_ID,
                'coach_id' => $coachId,
                'status' => 'pending',
            ]);

            // Send email to Coach
            $coach = User::find($coachId);
            Mail::to($coach->email)->send(new NewMentorshipRequest($mentorshipRequest));

            DB::commit();
            Log::info('Mentorship request created', [
                'mentorship_request_id' => $mentorshipRequest->id,
            ]);

            return response()->json([
                'message' => 'Mentorship request sent successfully.',
                'request' => $mentorshipRequest
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create mentorship request', [
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function traineegetrequest(Request $request)
    {
        $user = auth()->user();

        if ($user->role_profile !== 'Trainee') {
            return response()->json(['message' => 'Only trainees can view their requests.'], 403);
        }

        $requests = MentorshipRequest::with('coach', 'requestable')
            ->where('trainee_id', $user->User_ID)
            ->latest()
            ->get();

        return response()->json($requests);
    }

    public function viewRequests()
    {
        $user = auth()->user();

        if ($user->role_profile !== 'Coach') {
            return response()->json(['message' => 'Only coaches can view their requests.'], 403);
        }

        $requests = MentorshipRequest::with('trainee', 'requestable')
            ->where('coach_id', $user->User_ID)
            ->where('status', 'pending')
            ->latest()
            ->get();

        return response()->json($requests);
    }

    public function acceptRequest($id)
    {
        $user = auth()->user();
        if ($user->role_profile !== 'Coach') {
            return response()->json(['message' => 'Only coaches can accept requests.'], 403);
        }

        $mentorshipRequest = MentorshipRequest::find($id);

        if (!$mentorshipRequest) {
            return response()->json(['message' => 'Request not found.'], 404);
        }

        if ($mentorshipRequest->coach_id !== $user->User_ID) {
            return response()->json(['message' => 'This request does not belong to you.'], 403);
        }

        if ($mentorshipRequest->status !== 'pending') {
            Log::warning('Request cannot be accepted', [
                'request_id' => $id,
                'status' => $mentorshipRequest->status
            ]);
            return response()->json(['message' => 'Request cannot be accepted'], 400);
        }

        DB::beginTransaction();
        try {
            $mentorshipRequest->status = 'accepted';
            $mentorshipRequest->responded_at = Carbon::now();
            $mentorshipRequest->save();

            DB::commit();
            Log::info('Mentorship request accepted', [
                'request_id' => $id,
            ]);

            return response()->json([
                'message' => 'Request accepted successfully. Please schedule your sessions (if applicable) or proceed to payment.',
                'request' => $mentorshipRequest->fresh(['requestable', 'trainee.user'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to accept mentorship request', [
                'request_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function rejectRequest($id)
    {
        $user = auth()->user();

        if ($user->role_profile !== 'Coach') {
            return response()->json(['message' => 'Only coaches can reject requests.'], 403);
        }

        $mentorshipRequest = MentorshipRequest::find($id);

        if (!$mentorshipRequest) {
            return response()->json(['message' => 'Request not found.'], 404);
        }

        if ($mentorshipRequest->coach_id !== $user->User_ID) {
            return response()->json(['message' => 'This request does not belong to you.'], 403);
        }

        if ($mentorshipRequest->status !== 'pending') {
            Log::warning('Request cannot be rejected', [
                'request_id' => $id,
                'status' => $mentorshipRequest->status
            ]);
            return response()->json(['message' => 'Request cannot be rejected'], 400);
        }

        DB::beginTransaction();
        try {
            $mentorshipRequest->status = 'rejected';
            $mentorshipRequest->responded_at = Carbon::now();
            $mentorshipRequest->save();

            DB::commit();
            Log::info('Mentorship request rejected', [
                'request_id' => $id,
            ]);

            return response()->json([
                'message' => 'Request rejected successfully.',
                'request' => $mentorshipRequest->fresh(['requestable', 'trainee.user'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to reject mentorship request', [
                'request_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function scheduleSessions(Request $request, $id)
    {
        $mentorshipRequest = MentorshipRequest::findOrFail($id);

        if ($mentorshipRequest->trainee_id !== Auth::user()->User_ID) {
            return response()->json(['message' => 'This request does not belong to you.'], 403);
        }

        if ($mentorshipRequest->status !== 'accepted') {
            return response()->json(['message' => 'Request must be accepted to schedule sessions.'], 400);
        }

        if ($mentorshipRequest->requestable_type !== \App\Models\MentorshipPlan::class) {
            return response()->json(['message' => 'Scheduling is only for Mentorship Plans.'], 400);
        }

        $mentorshipPlan = $mentorshipRequest->requestable;
        $sessionCount = $mentorshipPlan->session_count;

        $request->validate([
            'sessions' => 'required|array|size:' . $sessionCount,
            'sessions.*.session_time' => 'required|date|after:now',
            'sessions.*.duration_minutes' => 'required|integer|min:30',
        ]);

        $coachId = $mentorshipRequest->coach_id;
        $sessions = $request->input('sessions');
        $planSchedule = [];

        foreach ($sessions as $index => $session) {
            $slotStart = Carbon::parse($session['session_time']);
            $slotEnd = $slotStart->copy()->addMinutes($session['duration_minutes']);
            $dayOfWeek = $slotStart->format('l');

            // Check Coach Availability
            $availability = CoachAvailability::where('User_ID', (int)$coachId)
                ->where('Day_Of_Week', $dayOfWeek)
                ->where('Start_Time', '<=', $slotStart->format('H:i:s'))
                ->where('End_Time', '>=', $slotEnd->format('H:i:s'))
                ->first();

            if (!$availability) {
                Log::warning('Selected slot is not available', [
                    'trainee_id' => Auth::id(),
                    'request_id' => $id,
                    'session_index' => $index,
                    'date' => $slotStart->toDateString(),
                    'day_of_week' => $dayOfWeek,
                    'start_time' => $slotStart->format('H:i'),
                    'duration' => $session['duration_minutes'],
                ]);
                return response()->json(['message' => "Session $index: Selected slot is not available"], 400);
            }

            // Check for conflicts
            $conflictingSessions = NewSession::where('coach_id', (int)$coachId)
                ->whereIn('status', ['pending', 'upcoming'])
                ->whereDate('session_time', $slotStart->toDateString())
                ->get()
                ->filter(function ($existingSession) use ($slotStart, $slotEnd) {
                    $reqStart = Carbon::parse($existingSession->session_time);
                    $reqEnd = $reqStart->copy()->addMinutes($existingSession->duration_minutes);
                    return $slotStart < $reqEnd && $slotEnd > $reqStart;
                });

            if ($conflictingSessions->isNotEmpty()) {
                Log::warning('Slot conflicts with existing sessions', [
                    'trainee_id' => Auth::id(),
                    'request_id' => $id,
                    'session_index' => $index,
                    'date' => $slotStart->toDateString(),
                    'day_of_week' => $dayOfWeek,
                    'start_time' => $slotStart->format('H:i'),
                ]);
                return response()->json(['message' => "Session $index: Selected slot is already reserved"], 400);
            }

            $planSchedule[] = [
                'session_time' => $slotStart->toDateTimeString(),
                'duration_minutes' => $session['duration_minutes'],
            ];
        }

        DB::beginTransaction();
        try {
            $mentorshipRequest->status = 'pending_payment';
            $mentorshipRequest->save();

            PendingPayment::create([
                'mentorship_request_id' => $mentorshipRequest->id,
                'payment_due_at' => Carbon::now()->addHours(24),
            ]);

            // Temporarily store session schedule in new_sessions with status 'pending'
            foreach ($planSchedule as $session) {
                NewSession::create([
                    'mentorship_request_id' => $mentorshipRequest->id,
                    'trainee_id' => $mentorshipRequest->trainee_id,
                    'coach_id' => $mentorshipRequest->coach_id,
                    'session_time' => $session['session_time'],
                    'duration_minutes' => $session['duration_minutes'],
                    'status' => 'pending',
                ]);
            }

            // Send payment email to Trainee
            $trainee = User::find($mentorshipRequest->trainee_id);
            Mail::to($trainee->email)->send(new PaymentReminder($mentorshipRequest));

            DB::commit();
            Log::info('Sessions scheduled, awaiting payment', [
                'mentorship_request_id' => $mentorshipRequest->id,
            ]);

            return response()->json([
                'message' => 'Sessions scheduled successfully. Please proceed to payment.',
                'request' => $mentorshipRequest->fresh(['requestable', 'trainee.user'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to schedule sessions', [
                'request_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function initiatePayment($id)
    {
        $mentorshipRequest = MentorshipRequest::findOrFail($id);

        if ($mentorshipRequest->status !== 'pending_payment') {
            return response()->json(['message' => 'Payment cannot be initiated for this request'], 400);
        }

        $pendingPayment = PendingPayment::where('mentorship_request_id', $id)->first();
        if (!$pendingPayment || $pendingPayment->payment_due_at < Carbon::now()) {
            $mentorshipRequest->status = 'cancelled';
            $mentorshipRequest->save();

            // Delete pending sessions
            NewSession::where('mentorship_request_id', $id)
                ->where('status', 'pending')
                ->delete();

            return response()->json(['message' => 'Payment deadline has passed. Request cancelled.'], 400);
        }

        Stripe::setApiKey(env('STRIPE_SECRET_KEY'));

        try {
            $price = $this->calculatePrice($mentorshipRequest);

            $session = StripeSession::create([
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'egp',
                        'product_data' => [
                            'name' => $this->getServiceName($mentorshipRequest),
                        ],
                        'unit_amount' => $price * 100,
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => env('FRONTEND_URL') . '/payment/success?session_id={CHECKOUT_SESSION_ID}&request_id=' . $id,
                'cancel_url' => env('FRONTEND_URL') . '/payment/cancel',
                'metadata' => [
                    'mentorship_request_id' => $id,
                ],
            ]);

            return response()->json([
                'message' => 'Payment session created successfully.',
                'checkout_url' => $session->url,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create Stripe Checkout Session', [
                'request_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function completePayment(Request $request)
    {
        $sessionId = $request->query('session_id');
        $requestId = $request->query('request_id');

        $mentorshipRequest = MentorshipRequest::findOrFail($requestId);

        if ($mentorshipRequest->status !== 'pending_payment') {
            return response()->json(['message' => 'Payment cannot be completed for this request'], 400);
        }

        Stripe::setApiKey(env('STRIPE_SECRET_KEY'));

        try {
            $session = StripeSession::retrieve($sessionId);

            if ($session->payment_status !== 'paid') {
                Log::warning('Payment not completed', [
                    'request_id' => $requestId,
                    'session_id' => $sessionId,
                    'payment_status' => $session->payment_status,
                ]);
                return response()->json(['message' => 'Payment not completed'], 400);
            }

            DB::beginTransaction();
            try {
                $mentorshipRequest->status = 'accepted';
                $mentorshipRequest->save();

                // Delete pending payment record
                PendingPayment::where('mentorship_request_id', $requestId)->delete();

                // If GroupMentorship, update trainee_ids and create session
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
                } else {
                    // For MentorshipPlan, update pending sessions to upcoming
                    NewSession::where('mentorship_request_id', $mentorshipRequest->id)
                        ->where('status', 'pending')
                        ->update(['status' => 'upcoming']);
                }

                // Send email to Trainee
                $trainee = User::find($mentorshipRequest->trainee_id);
                Mail::to($trainee->email)->send(new SessionBooked($mentorshipRequest));

                DB::commit();
                Log::info('Payment completed and sessions scheduled', [
                    'mentorship_request_id' => $requestId,
                    'session_id' => $sessionId,
                ]);

                return response()->json([
                    'message' => 'Payment completed successfully. Sessions scheduled.',
                    'request' => $mentorshipRequest->fresh(['requestable', 'trainee.user'])
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Failed to complete payment', [
                    'request_id' => $requestId,
                    'error' => $e->getMessage(),
                ]);
                return response()->json(['error' => $e->getMessage()], 500);
            }
        } catch (\Exception $e) {
            Log::error('Failed to retrieve Stripe Checkout Session', [
                'request_id' => $requestId,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function calculatePrice(MentorshipRequest $request)
    {
        $pricePerSession = 150; // EGP 150 per session (as seen in the card)
        
        if ($request->requestable_type === \App\Models\MentorshipPlan::class) {
            $mentorshipPlan = $request->requestable;
            return $pricePerSession * $mentorshipPlan->session_count;
        }
        
        return $pricePerSession; // For GroupMentorship
    }

    private function getServiceName(MentorshipRequest $request)
    {
        return $request->requestable->title;
    }
}
