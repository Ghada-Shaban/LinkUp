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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use App\Mail\RequestAccepted;
use App\Mail\RequestRejected;
use App\Mail\NewMentorshipRequest;
// use App\Mail\PaymentReminder;
// use Stripe\Stripe;
// use Stripe\Checkout\Session as StripeSession;

class MentorshipRequestController extends Controller
{
    public function requestMentorship(Request $request)
    {
        $request->validate([
            'service_id' => 'required|exists:services,service_id',
            'service_type' => 'required|in:MentorshipPlan,GroupMentorship,MentorshipSession,MockInterview',
            'first_session_time' => [
                'required_if:service_type,MentorshipSession,MockInterview,MentorshipPlan',
                'date',
                function ($attribute, $value, $fail) {
                    if (strtotime($value) <= time()) {
                        $fail('The first session time must be in the future');
                    }
                }
            ],
            'duration_minutes' => 'required_if:service_type,MentorshipSession,MockInterview,MentorshipPlan|integer|min:30',
        ]);

        $typeMap = [
            'MentorshipPlan' => \App\Models\MentorshipPlan::class,
            'GroupMentorship' => \App\Models\GroupMentorship::class,
            'MentorshipSession' => \App\Models\Service::class,
            'MockInterview' => \App\Models\Service::class,
        ];

        $serviceTypeInput = $request->input('service_type');
        $modelClass = $typeMap[$serviceTypeInput];
        $serviceId = $request->input('service_id');

        // Get the actual service
        $service = $modelClass::findOrFail($serviceId);

        // Get the coach_id
        $coachId = ($serviceTypeInput === 'MentorshipPlan' || $serviceTypeInput === 'GroupMentorship') 
            ? $service->service->coach_id 
            : $service->coach_id;

        Log::debug('Service details in requestMentorship', [
            'service_id' => $serviceId,
            'service_type' => $serviceTypeInput,
            'coach_id' => $coachId,
        ]);

        // Check availability for MentorshipSession, MockInterview, and MentorshipPlan
        $planSchedule = null;
        if (in_array($serviceTypeInput, ['MentorshipSession', 'MockInterview', 'MentorshipPlan'])) {
            $slotStart = Carbon::parse($request->first_session_time);
            $slotEnd = $slotStart->copy()->addMinutes($request->duration_minutes);
            $dayOfWeek = $slotStart->format('l');

            // Check Coach Availability
            $availability = CoachAvailability::where('coach_id', (int)$coachId)
                ->where('Day_Of_Week', $dayOfWeek)
                ->where('Start_Time', '<=', $slotStart->format('H:i:s'))
                ->where('End_Time', '>=', $slotEnd->format('H:i:s'))
                ->first();

            if (!$availability) {
                Log::warning('Selected slot is not available', [
                    'trainee_id' => Auth::id(),
                    'service_id' => $serviceId,
                    'date' => $slotStart->toDateString(),
                    'day_of_week' => $dayOfWeek,
                    'start_time' => $slotStart->format('H:i'),
                    'duration' => $request->duration_minutes,
                ]);
                return response()->json(['message' => 'Selected slot is not available'], 400);
            }

            // Check for conflicts
            $conflictingRequests = MentorshipRequest::where('coach_id', (int)$coachId)
                ->whereIn('status', ['pending', /*'pending_payment',*/ 'accepted'])
                ->whereDate('first_session_time', $slotStart->toDateString())
                ->get()
                ->filter(function ($req) use ($slotStart, $slotEnd) {
                    $reqStart = Carbon::parse($req->first_session_time);
                    $reqEnd = $reqStart->copy()->addMinutes($req->duration_minutes);
                    return $slotStart < $reqEnd && $slotEnd > $reqStart;
                });

            if ($conflictingRequests->isNotEmpty()) {
                Log::warning('Slot conflicts with existing requests', [
                    'trainee_id' => Auth::id(),
                    'service_id' => $serviceId,
                    'date' => $slotStart->toDateString(),
                    'day_of_week' => $dayOfWeek,
                    'start_time' => $slotStart->format('H:i'),
                ]);
                return response()->json(['message' => 'Selected slot is already reserved'], 400);
            }

            // For MentorshipPlan, check all 4 sessions
            if ($serviceTypeInput === 'MentorshipPlan') {
                $planSchedule = [];
                $sessionTime = new \DateTime($request->first_session_time);
                
                for ($i = 0; $i < 4; $i++) {
                    $currentSessionTime = Carbon::parse($sessionTime->format('Y-m-d H:i:s'));
                    $currentSessionEnd = $currentSessionTime->copy()->addMinutes($request->duration_minutes);
                    $currentDayOfWeek = $currentSessionTime->format('l');

                    $sessionAvailability = CoachAvailability::where('coach_id', (int)$coachId)
                        ->where('Day_Of_Week', $currentDayOfWeek)
                        ->where('Start_Time', '<=', $currentSessionTime->format('H:i:s'))
                        ->where('End_Time', '>=', $currentSessionEnd->format('H:i:s'))
                        ->first();

                    if (!$sessionAvailability) {
                        Log::warning('Plan session slot is not available', [
                            'trainee_id' => Auth::id(),
                            'service_id' => $serviceId,
                            'date' => $currentSessionTime->toDateString(),
                            'day_of_week' => $currentDayOfWeek,
                            'start_time' => $currentSessionTime->format('H:i'),
                        ]);
                        return response()->json(['message' => "Session $i is not available"], 400);
                    }

                    $sessionConflicts = MentorshipRequest::where('coach_id', (int)$coachId)
                        ->whereIn('status', ['pending', /*'pending_payment',*/ 'accepted'])
                        ->whereDate('first_session_time', $currentSessionTime->toDateString())
                        ->get()
                        ->filter(function ($req) use ($currentSessionTime, $currentSessionEnd) {
                            $reqStart = Carbon::parse($req->first_session_time);
                            $reqEnd = $reqStart->copy()->addMinutes($req->duration_minutes);
                            return $currentSessionTime < $reqEnd && $currentSessionEnd > $reqStart;
                        });

                    if ($sessionConflicts->isNotEmpty()) {
                        Log::warning('Plan session slot conflicts with existing requests', [
                            'trainee_id' => Auth::id(),
                            'service_id' => $serviceId,
                            'date' => $currentSessionTime->toDateString(),
                            'day_of_week' => $currentDayOfWeek,
                            'start_time' => $currentSessionTime->format('H:i'),
                        ]);
                        return response()->json(['message' => "Session $i is already reserved"], 400);
                    }

                    $planSchedule[] = $sessionTime->format('Y-m-d H:i:s');
                    $sessionTime->modify('+7 days');
                }
            }
        }

        DB::beginTransaction();
        try {
            // $status = in_array($serviceTypeInput, ['MentorshipPlan', 'GroupMentorship']) ? 'pending' : 'pending_payment';
            // $paymentDueAt = Carbon::now()->addHours(24);
            $status = in_array($serviceTypeInput, ['MentorshipPlan', 'GroupMentorship']) ? 'pending' : 'accepted'; // بدون الدفع، بنحول مباشرة لـ accepted

            $mentorshipRequest = MentorshipRequest::create([
                'requestable_id' => $serviceId,
                'requestable_type' => $modelClass,
                'trainee_id' => Auth::user()->User_ID,
                'coach_id' => $coachId,
                'status' => $status,
                'first_session_time' => $request->first_session_time,
                'duration_minutes' => $request->duration_minutes,
                'plan_schedule' => $planSchedule,
                // 'payment_due_at' => $paymentDueAt,
            ]);

            // Send email to Trainee for payment if not MentorshipPlan or GroupMentorship
            /* if ($status === 'pending_payment') {
                $trainee = User::find(Auth::user()->User_ID);
                Mail::to($trainee->email)->send(new PaymentReminder($mentorshipRequest));
            } else {
                // Send email to Coach for approval
                $coach = User::find($coachId);
                Mail::to($coach->email)->send(new NewMentorshipRequest($mentorshipRequest));
            } */
            
            // بدون الدفع، بنرسل إيميل للـ Coach لو الطلب pending (لـ MentorshipPlan أو GroupMentorship)
            if ($status === 'pending') {
                $coach = User::find($coachId);
                Mail::to($coach->email)->send(new NewMentorshipRequest($mentorshipRequest));
            }

            // لو الطلب accepted مباشرة (MentorshipSession أو MockInterview)، بننشئ الجلسات فورًا
            if ($status === 'accepted') {
                if ($mentorshipRequest->plan_schedule) {
                    foreach ($mentorshipRequest->plan_schedule as $sessionTime) {
                        NewSession::create([
                            'mentorship_request_id' => $mentorshipRequest->id,
                            'trainee_id' => $mentorshipRequest->trainee_id,
                            'coach_id' => $mentorshipRequest->coach_id,
                            'session_time' => $sessionTime,
                            'duration_minutes' => $mentorshipRequest->duration_minutes,
                            'status' => 'upcoming',
                        ]);
                    }
                } else {
                    NewSession::create([
                        'mentorship_request_id' => $mentorshipRequest->id,
                        'trainee_id' => $mentorshipRequest->trainee_id,
                        'coach_id' => $mentorshipRequest->coach_id,
                        'session_time' => $mentorshipRequest->first_session_time,
                        'duration_minutes' => $mentorshipRequest->duration_minutes,
                        'status' => 'upcoming',
                    ]);
                }
            }

            DB::commit();
            Log::info('Mentorship request created', [
                'mentorship_request_id' => $mentorshipRequest->id,
            ]);

            return response()->json([
                'message' => 'Mentorship request sent successfully.',
                'request' => $mentorshipRequest->load(['requestable', 'coach.user'])
            ], 201);
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
            // $mentorshipRequest->payment_due_at = Carbon::now()->addHours(24);
            $mentorshipRequest->save();

            // Create sessions in new_sessions
            if ($mentorshipRequest->plan_schedule) {
                foreach ($mentorshipRequest->plan_schedule as $sessionTime) {
                    NewSession::create([
                        'mentorship_request_id' => $mentorshipRequest->id,
                        'trainee_id' => $mentorshipRequest->trainee_id,
                        'coach_id' => $mentorshipRequest->coach_id,
                        'session_time' => $sessionTime,
                        'duration_minutes' => $mentorshipRequest->duration_minutes,
                        'status' => 'upcoming',
                    ]);
                }
            } else {
                NewSession::create([
                    'mentorship_request_id' => $mentorshipRequest->id,
                    'trainee_id' => $mentorshipRequest->trainee_id,
                    'coach_id' => $mentorshipRequest->coach_id,
                    'session_time' => $mentorshipRequest->first_session_time,
                    'duration_minutes' => $mentorshipRequest->duration_minutes,
                    'status' => 'upcoming',
                ]);
            }

            // Send email to Trainee for payment
            $trainee = User::find($mentorshipRequest->trainee_id);
            Mail::to($trainee->email)->send(new RequestAccepted($mentorshipRequest));

            DB::commit();
            Log::info('Mentorship request accepted', [
                'request_id' => $id,
            ]);

            return response()->json([
                'message' => 'Request accepted successfully.',
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
            $mentorshipRequest->save();

            // Send email to Trainee
            $trainee = User::find($mentorshipRequest->trainee_id);
            Mail::to($trainee->email)->send(new RequestRejected($mentorshipRequest));

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

    // تعليق دوال Stripe مؤقتًا
    /*
    public function initiatePayment($id)
    {
        $mentorshipRequest = MentorshipRequest::findOrFail($id);

        if ($mentorshipRequest->status !== 'pending_payment') {
            return response()->json(['message' => 'Payment cannot be initiated for this request'], 400);
        }

        // Set Stripe API Key
        Stripe::setApiKey(env('STRIPE_SECRET_KEY'));

        try {
            // Get service details to determine price
            $service = $mentorshipRequest->requestable;
            $price = $this->calculatePrice($mentorshipRequest);

            // Create Stripe Checkout Session
            $session = StripeSession::create([
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'usd',
                        'product_data' => [
                            'name' => $this->getServiceName($mentorshipRequest),
                        ],
                        'unit_amount' => $price * 100, // Stripe expects amount in cents
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

        // Set Stripe API Key
        Stripe::setApiKey(env('STRIPE_SECRET_KEY'));

        try {
            // Retrieve the Stripe Checkout Session
            $session = StripeSession::retrieve($sessionId);

            // Check if payment was successful
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
                $mentorshipRequest->payment_due_at = null;
                $mentorshipRequest->save();

                // Create sessions in new_sessions
                if ($mentorshipRequest->plan_schedule) {
                    foreach ($mentorshipRequest->plan_schedule as $sessionTime) {
                        NewSession::create([
                            'mentorship_request_id' => $mentorshipRequest->id,
                            'trainee_id' => $mentorshipRequest->trainee_id,
                            'coach_id' => $mentorshipRequest->coach_id,
                            'session_time' => $sessionTime,
                            'duration_minutes' => $mentorshipRequest->duration_minutes,
                            'status' => 'upcoming',
                        ]);
                    }
                } else {
                    NewSession::create([
                        'mentorship_request_id' => $mentorshipRequest->id,
                        'trainee_id' => $mentorshipRequest->trainee_id,
                        'coach_id' => $mentorshipRequest->coach_id,
                        'session_time' => $mentorshipRequest->first_session_time,
                        'duration_minutes' => $mentorshipRequest->duration_minutes,
                        'status' => 'upcoming',
                    ]);
                }

                DB::commit();
                Log::info('Payment completed and sessions created', [
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
        $service = $request->requestable;
        $price = 0;

        if ($request->requestable_type === \App\Models\MentorshipPlan::class) {
            $price = $service->price_per_session * 4; // 4 sessions
        } elseif ($request->requestable_type === \App\Models\GroupMentorship::class) {
            $price = $service->price;
        } else {
            $price = $service->price;
        }

        return $price;
    }

    private function getServiceName(MentorshipRequest $request)
    {
        if ($request->requestable_type === \App\Models\MentorshipPlan::class) {
            return 'Mentorship Plan (4 Sessions)';
        } elseif ($request->requestable_type === \App\Models\GroupMentorship::class) {
            return 'Group Mentorship';
        } else {
            return 'Mentorship Session';
        }
    }
    */
}
