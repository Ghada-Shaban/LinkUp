<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MentorshipRequest;
use App\Models\CoachAvailability; // تغيير من Availability إلى CoachAvailability
use App\Models\Service;
use App\Models\MentorshipPlan;
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

class MentorshipRequestController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'service_id' => [
                'required',
                'exists:services,service_id',
                function ($attribute, $value, $fail) use ($request) {
                    $service = Service::find($value);
                    
                    if (!$service) {
                        $fail('The selected service does not exist');
                        return;
                    }

                    // Debug Log لـ coach_id
                    Log::debug('Service details in store', [
                        'service_id' => $value,
                        'coach_id' => $service->coach_id,
                        'coach_id_type' => gettype($service->coach_id),
                    ]);

                    // For Plan requests, check it's linked to a 4-session plan
                    if ($request->type === 'Plan') {
                        $plan = MentorshipPlan::where('service_id', $value)
                                    ->where('session_count', 4)
                                    ->exists();
                        
                        if (!$plan) {
                            $fail('This service is not configured as a 4-session plan');
                        }
                    }
                }
            ],
            'title' => 'required|string|max:255',
            'type' => ['required', Rule::in(['One_to_One', 'Group', 'Plan'])],
            'first_session_time' => [
                'required',
                'date',
                function ($attribute, $value, $fail) {
                    if (strtotime($value) <= time()) {
                        $fail('The first session time must be in the future');
                    }
                }
            ],
            'duration_minutes' => 'required|integer|min:30',
            'mentorship_plan_id' => [
                Rule::requiredIf($request->type === 'Plan'),
                'nullable',
                'exists:mentorship_plans,service_id',
                function ($attribute, $value, $fail) {
                    $plan = MentorshipPlan::find($value);
                    if ($plan && $plan->session_count != 4) {
                        $fail('The selected plan must have exactly 4 sessions');
                    }
                }
            ]
        ]);

        // التحقق من توفر الـ Slot في coach_available_times
        $service = Service::findOrFail($validated['service_id']);
        $slotStart = Carbon::parse($validated['first_session_time']);
        $slotEnd = $slotStart->copy()->addMinutes($validated['duration_minutes']);
        $dayOfWeek = $slotStart->format('l'); // Monday, Tuesday, ...

        $availability = CoachAvailability::where('User_ID', (int)$service->coach_id)
            ->where('Day_Of_Week', $dayOfWeek)
            ->where('Start_Time', '<=', $slotStart->format('H:i:s'))
            ->where('End_Time', '>=', $slotEnd->format('H:i:s'))
            ->first();

        if (!$availability) {
            Log::warning('Selected slot is not available', [
                'trainee_id' => Auth::id(),
                'service_id' => $validated['service_id'],
                'date' => $slotStart->toDateString(),
                'day_of_week' => $dayOfWeek,
                'start_time' => $slotStart->format('H:i'),
                'duration' => $validated['duration_minutes'],
            ]);
            return response()->json(['message' => 'Selected slot is not available'], 400);
        }

        // التحقق من عدم التداخل مع طلبات أخرى
        $conflictingRequests = MentorshipRequest::where('coach_id', (int)$service->coach_id)
            ->whereIn('status', ['pending', 'accepted'])
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
                'service_id' => $validated['service_id'],
                'date' => $slotStart->toDateString(),
                'day_of_week' => $dayOfWeek,
                'start_time' => $slotStart->format('H:i'),
            ]);
            return response()->json(['message' => 'Selected slot is already reserved'], 400);
        }

        // التحقق من الـ Plan (لكل جلسة في الجدول)
        $planSchedule = null;
        if ($validated['type'] === 'Plan') {
            $planSchedule = [];
            $sessionTime = new \DateTime($validated['first_session_time']);
            
            for ($i = 0; $i < 4; $i++) {
                $currentSessionTime = Carbon::parse($sessionTime->format('Y-m-d H:i:s'));
                $currentSessionEnd = $currentSessionTime->copy()->addMinutes($validated['duration_minutes']);
                $currentDayOfWeek = $currentSessionTime->format('l');

                // التحقق من توفر الجلسة في coach_available_times
                $sessionAvailability = CoachAvailability::where('User_ID', (int)$service->coach_id)
                    ->where('Day_Of_Week', $currentDayOfWeek)
                    ->where('Start_Time', '<=', $currentSessionTime->format('H:i:s'))
                    ->where('End_Time', '>=', $currentSessionEnd->format('H:i:s'))
                    ->first();

                if (!$sessionAvailability) {
                    Log::warning('Plan session slot is not available', [
                        'trainee_id' => Auth::id(),
                        'service_id' => $validated['service_id'],
                        'date' => $currentSessionTime->toDateString(),
                        'day_of_week' => $currentDayOfWeek,
                        'start_time' => $currentSessionTime->format('H:i'),
                    ]);
                    return response()->json(['message' => "Session $i is not available"], 400);
                }

                // التحقق من عدم التداخل مع طلبات أخرى
                $sessionConflicts = MentorshipRequest::where('coach_id', (int)$service->coach_id)
                    ->whereIn('status', ['pending', 'accepted'])
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
                        'service_id' => $validated['service_id'],
                        'date' => $currentSessionTime->toDateString(),
                        'day_of_week' => $currentDayOfWeek,
                        'start_time' => $currentSessionTime->format('H:i'),
                    ]);
                    return response()->json(['message' => "Session $i is already reserved"], 400);
                }

                $planSchedule[] = $sessionTime->format('Y-m-d H:i:s');
                $sessionTime->modify('+7 days'); // Weekly sessions
            }
        }

        DB::beginTransaction();
        try {
            // إنشاء طلب Mentorship
            $mentorshipRequest = MentorshipRequest::create([
                'trainee_id' => Auth::id(),
                'coach_id' => (int)$service->coach_id,
                'service_id' => $validated['service_id'],
                'title' => $validated['title'],
                'type' => $validated['type'],
                'first_session_time' => $validated['first_session_time'],
                'duration_minutes' => $validated['duration_minutes'],
                'plan_schedule' => $planSchedule,
                'mentorship_plan_id' => $validated['mentorship_plan_id'] ?? null,
                'status' => 'pending'
            ]);

            // إرسال إيميل للـ Coach
            $coach = User::find($service->coach_id);
            Mail::to($coach->email)->send(new NewMentorshipRequest($mentorshipRequest));

            DB::commit();
            Log::info('Mentorship request created', [
                'mentorship_request_id' => $mentorshipRequest->id,
            ]);

            $response = [
                'status' => 'success',
                'message' => $validated['type'] === 'Plan' 
                    ? '4-session plan created successfully' 
                    : 'Mentorship request created successfully',
                'data' => $mentorshipRequest->load(['service', 'coach.user'])
            ];

            if ($validated['type'] === 'Plan') {
                $response['data']['scheduled_sessions'] = $planSchedule;
                $response['data']['total_hours'] = ($validated['duration_minutes'] * 4) / 60;
            }

            return response()->json($response, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create mentorship request', [
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function traineeIndex()
    {
        $requests = MentorshipRequest::with(['service', 'coach.user'])
            ->where('trainee_id', Auth::id())
            ->latest()
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $requests->groupBy('status')
        ]);
    }

    public function coachPendingRequests()
    {
        $requests = MentorshipRequest::with(['service', 'trainee.user'])
            ->where('coach_id', Auth::id())
            ->where('status', 'pending')
            ->latest()
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $requests
        ]);
    }

    public function acceptRequest($id)
    {
        $request = MentorshipRequest::where('coach_id', Auth::id())
            ->findOrFail($id);

        if ($request->status !== 'pending') {
            Log::warning('Request cannot be accepted', [
                'request_id' => $id,
                'status' => $request->status
            ]);
            return response()->json(['message' => 'Request cannot be accepted'], 400);
        }

        DB::beginTransaction();
        try {
            // تحديث حالة الطلب
            $request->status = 'accepted';
            $request->save();

            // الـ Observer هيظبط إنشاء الجلسات في new_sessions
            // تحديث coach_available_times لو مش في الـ Observer
            if ($request->type === 'Plan') {
                foreach ($request->plan_schedule as $sessionTime) {
                    $sessionStart = Carbon::parse($sessionTime);
                    $sessionEnd = $sessionStart->copy()->addMinutes($request->duration_minutes);
                    $dayOfWeek = $sessionStart->format('l');

                    $availability = CoachAvailability::where('User_ID', (int)$request->coach_id)
                        ->where('Day_Of_Week', $dayOfWeek)
                        ->where('Start_Time', '<=', $sessionStart->format('H:i:s'))
                        ->where('End_Time', '>=', $sessionEnd->format('H:i:s'))
                        ->first();

                    // ملاحظة: مافيش is_booked، فمش هنحدّث حاجة هنا
                    // لو عايز تضيف إدارة الحجز، هتحتاج تعديل الجدول
                }
            } else {
                $sessionStart = Carbon::parse($request->first_session_time);
                $sessionEnd = $sessionStart->copy()->addMinutes($request->duration_minutes);
                $dayOfWeek = $sessionStart->format('l');

                $availability = CoachAvailability::where('User_ID', (int)$request->coach_id)
                    ->where('Day_Of_Week', $dayOfWeek)
                    ->where('Start_Time', '<=', $sessionStart->format('H:i:s'))
                    ->where('End_Time', '>=', $sessionEnd->format('H:i:s'))
                    ->first();

                // ملاحظة: مافيش is_booked، فمش هنحدّث حاجة هنا
            }

            // إرسال إيميل للـ Trainee
            $trainee = User::find($request->trainee_id);
            Mail::to($trainee->email)->send(new RequestAccepted($request));

            DB::commit();
            Log::info('Mentorship request accepted', [
                'request_id' => $id,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Request accepted successfully',
                'data' => $request->fresh(['service', 'trainee.user'])
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
        $request = MentorshipRequest::where('coach_id', Auth::id())
            ->findOrFail($id);

        if ($request->status !== 'pending') {
            Log::warning('Request cannot be rejected', [
                'request_id' => $id,
                'status' => $request->status
            ]);
            return response()->json(['message' => 'Request cannot be rejected'], 400);
        }

        DB::beginTransaction();
        try {
            $request->status = 'rejected';
            $request->save();

            // إرسال إيميل للـ Trainee
            $trainee = User::find($request->trainee_id);
            Mail::to($trainee->email)->send(new RequestRejected($request));

            DB::commit();
            Log::info('Mentorship request rejected', [
                'request_id' => $id,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Request rejected successfully',
                'data' => $request->fresh(['service', 'trainee.user'])
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

    private function generatePlanSchedule($firstSessionTime)
    {
        $sessions = [];
        $date = new \DateTime($firstSessionTime);
        
        for ($i = 0; $i < 4; $i++) {
            $sessions[] = $date->format('Y-m-d H:i:s');
            $date->modify('+1 week');
        }
        
        return $sessions;
    }
}
