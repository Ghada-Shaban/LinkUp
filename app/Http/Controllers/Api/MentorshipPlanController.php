<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CoachAvailability;
use App\Models\NewSession;
use App\Models\Service;
use App\Models\MentorshipRequest;
use App\Models\GroupMentorship;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MentorshipPlanController extends Controller
    {
        public function bookMentorshipPlan(Request $request, $coachId)
        {
            $request->validate([
                'service_id' => 'required|exists:services,service_id',
                'start_time' => 'required|date_format:H:i:s',
                'start_date' => 'required|date|after:now',
                'mentorship_request_id' => 'required|exists:mentorship_requests,id',
            ]);

            // Log the incoming request data
            Log::info('Incoming bookMentorshipPlan request', [
                'coach_id' => $coachId,
                'service_id' => $request->service_id,
                'start_time' => $request->start_time,
                'start_date' => $request->start_date,
                'mentorship_request_id' => $request->mentorship_request_id,
                'user_id' => Auth::user()->User_ID,
            ]);

            $service = Service::findOrFail($request->service_id);
            if ($service->coach_id !== (int)$coachId) {
                return response()->json(['message' => 'الخدمة غير متاحة لهذا المدرب'], 403);
            }

            $mentorshipRequest = MentorshipRequest::findOrFail($request->mentorship_request_id);

            if ($mentorshipRequest->trainee_id !== Auth::user()->User_ID) {
                return response()->json(['message' => 'طلب المنتورشيب مش بتاعك'], 403);
            }

            if ($mentorshipRequest->status !== 'accepted') {
                return response()->json(['message' => 'طلب المنتورشيب لازم يكون مقبول عشان تحجز جلسات'], 400);
            }

            if ($mentorshipRequest->requestable_type !== \App\Models\MentorshipPlan::class) {
                return response()->json(['message' => 'الطلب مش خطة منتورشيب'], 400);
            }

            if ($mentorshipRequest->requestable->service_id !== $service->service_id) {
                return response()->json(['message' => 'الخدمة مش متطابقة مع طلب المنتورشيب'], 400);
            }

            $durationMinutes = 60;
            $startDate = Carbon::parse($request->start_date);
            $startTime = Carbon::parse($request->start_time);
            $dayOfWeek = $startDate->format('l');
            // Store time as-is (EEST), like BookingController
            $sessionDateTime = $startDate->setTime($startTime->hour, $startTime->minute, $startTime->second);
            $slotEnd = $sessionDateTime->copy()->addMinutes($durationMinutes);

            Log::info('Initial session date time for Mentorship Plan', [
                'start_date' => $request->start_date,
                'start_time' => $request->start_time,
                'session_date_time' => $sessionDateTime->toDateTimeString(),
                'timezone' => $sessionDateTime->getTimezone()->getName(),
                'slot_end' => $slotEnd->toDateTimeString(),
            ]);

            DB::beginTransaction();
            try {
                $sessionCount = 4;
                $bookedSessionsCount = NewSession::where('mentorship_request_id', $mentorshipRequest->id)->count();
                $remainingSessions = $sessionCount - $bookedSessionsCount;

                if ($remainingSessions <= 0) {
                    return response()->json(['message' => 'لقد حجزت بالفعل الحد الأقصى لعدد الجلسات لخطة المنتورشيب'], 400);
                }

                if ($remainingSessions < $sessionCount) {
                    return response()->json(['message' => 'خطة المنتورشيب بتتطلب حجز 4 جلسات مرة واحدة'], 400);
                }

                $sessionsToBook = [];
                for ($i = 0; $i < $sessionCount; $i++) {
                    $sessionDate = $startDate->copy()->addWeeks($i);
                    // Store time as-is (EEST)
                    $sessionDateTime = $sessionDate->setTime($startTime->hour, $startTime->minute, $startTime->second);
                    $slotEnd = $sessionDateTime->copy()->addMinutes($durationMinutes);

                    Log::info('Preparing Mentorship Plan session', [
                        'mentorship_request_id' => $mentorshipRequest->id,
                        'session_index' => $i,
                        'date_time' => $sessionDateTime->toDateTimeString(),
                        'timezone' => $sessionDateTime->getTimezone()->getName(),
                        'slot_end' => $slotEnd->toDateTimeString(),
                    ]);

                    $availability = CoachAvailability::where('coach_id', (int)$coachId)
                        ->where('Day_Of_Week', $sessionDate->format('l'))
                        ->where('Start_Time', '<=', $sessionDateTime->format('H:i:s'))
                        ->where('End_Time', '>=', $slotEnd->format('H:i:s'))
                        ->first();

                    if (!$availability) {
                        return response()->json(['message' => "السلوت المختار مش متاح في {$sessionDateTime->toDateString()}"], 400);
                    }

                    $conflictingSessions = NewSession::where('coach_id', (int)$coachId)
                        ->whereIn('status', ['Pending', 'Scheduled'])
                        ->whereDate('date_time', $sessionDateTime->toDateString())
                        ->whereDoesntHave('mentorshipRequest', function ($query) {
                            $query->where('requestable_type', GroupMentorship::class);
                        })
                        ->get()
                        ->filter(function ($existingSession) use ($sessionDateTime, $slotEnd) {
                            $reqStart = Carbon::parse($existingSession->date_time);
                            $reqEnd = $reqStart->copy()->addMinutes($existingSession->duration);
                            return $sessionDateTime->equalTo($reqStart) && $slotEnd->equalTo($reqEnd);
                        });

                    if ($conflictingSessions->isNotEmpty()) {
                        return response()->json(['message' => "السلوت المختار محجوز بالفعل في {$sessionDateTime->toDateString()}"], 400);
                    }

                    $sessionsToBook[] = [
                        'date_time' => $sessionDateTime->toDateTimeString(),
                        'duration' => $durationMinutes,
                    ];
                }

                $createdSessions = [];
                foreach ($sessionsToBook as $sessionData) {
                    $session = NewSession::create([
                        'trainee_id' => Auth::user()->User_ID,
                        'coach_id' => $coachId,
                        'date_time' => $sessionData['date_time'],
                        'duration' => $sessionData['duration'],
                        'status' => 'Pending',
                        'service_id' => $service->service_id,
                        'mentorship_request_id' => $mentorshipRequest->id,
                    ]);
                    $createdSessions[] = $session;

                    Log::info('Mentorship Plan session created', [
                        'new_session_id' => $session->new_session_id,
                        'date_time' => $session->date_time,
                        'mentorship_request_id' => $mentorshipRequest->id,
                    ]);
                }

                \App\Models\PendingPayment::create([
                    'mentorship_request_id' => $mentorshipRequest->id,
                    'payment_due_at' => now()->addHours(24),
                ]);

                DB::commit();
                Log::info('تم حجز كل الجلسات لخطة المنتورشيب، في انتظار الدفع', [
                    'mentorship_request_id' => $mentorshipRequest->id,
                    'sessions' => $createdSessions,
                ]);

                return response()->json([
                    'message' => 'تم حجز كل الجلسات بنجاح. تابع الدفع باستخدام /api/payment/initiate/mentorship_request/' . $mentorshipRequest->id,
                    'sessions' => $createdSessions,
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('فشل بدء الحجز لخطة المنتورشيب', [
                    'coach_id' => $coachId,
                    'mentorship_request_id' => $mentorshipRequest->id,
                    'error' => mb_convert_encoding($e->getMessage(), 'UTF-8', 'UTF-8'),
                ]);
                return response()->json(['message' => 'حدث خطأ أثناء الحجز: ' . mb_convert_encoding($e->getMessage(), 'UTF-8', 'UTF-8')], 500);
            }
        }
    }
}
