<?php

namespace App\Observers;

use App\Models\MentorshipRequest;
use App\Models\NewSession;
use App\Models\GroupMentorship;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\NewMentorshipRequest;
use App\Mail\RequestAccepted;
use App\Mail\RequestRejected;

class MentorshipRequestObserver
{
    /**
     * Handle the MentorshipRequest "created" event.
     */
    public function created(MentorshipRequest $mentorshipRequest)
    {
        // Send email to Coach when a new request is created
        $coach = User::find($mentorshipRequest->coach_id);
        if ($coach) {
            Mail::to($coach->email)->send(new NewMentorshipRequest($mentorshipRequest));
            Log::info('New mentorship request email sent to coach', [
                'request_id' => $mentorshipRequest->id,
                'coach_id' => $coach->User_ID,
            ]);
        } else {
            Log::warning('Coach not found for new mentorship request', [
                'request_id' => $mentorshipRequest->id,
                'coach_id' => $mentorshipRequest->coach_id,
            ]);
        }
    }

    /**
     * Handle the MentorshipRequest "updated" event.
     */
    public function updated(MentorshipRequest $mentorshipRequest)
    {
        // لو الـ Status اتغيرت
        if ($mentorshipRequest->isDirty('status')) {
            $oldStatus = $mentorshipRequest->getOriginal('status');
            $newStatus = $mentorshipRequest->status;

            Log::info('MentorshipRequest status changed', [
                'request_id' => $mentorshipRequest->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]);

            // لو الطلب بقى rejected
            if ($newStatus === 'rejected' && $oldStatus === 'pending') {
                // إلغاء أي جلسات مرتبطة (لو موجودة)
                NewSession::where('mentorship_request_id', $mentorshipRequest->id)
                    ->whereIn('status', ['pending', 'upcoming'])
                    ->update(['status' => 'cancelled']);

                // إذا كان GroupMentorship، إزالة الـ Trainee من القائمة
                if ($mentorshipRequest->requestable_type === \App\Models\GroupMentorship::class) {
                    $groupMentorship = $mentorshipRequest->requestable;
                    if ($groupMentorship) {
                        $groupMentorship->removeTrainee($mentorshipRequest->trainee_id);
                        Log::info('Trainee removed from GroupMentorship due to rejection', [
                            'request_id' => $mentorshipRequest->id,
                            'group_mentorship_id' => $groupMentorship->id,
                            'trainee_id' => $mentorshipRequest->trainee_id,
                        ]);
                    }
                }

                // إرسال إيميل للـ Trainee
                $trainee = $mentorshipRequest->trainee;
                if ($trainee) {
                    Mail::to($trainee->email)->send(new RequestRejected($mentorshipRequest));
                    Log::info('Rejection email sent to trainee', [
                        'request_id' => $mentorshipRequest->id,
                        'trainee_id' => $trainee->User_ID,
                    ]);
                } else {
                    Log::warning('Trainee not found for rejection email', [
                        'request_id' => $mentorshipRequest->id,
                        'trainee_id' => $mentorshipRequest->trainee_id,
                    ]);
                }
            }

            // لو الطلب بقى accepted
            if ($newStatus === 'accepted' && $oldStatus === 'pending') {
                // إرسال إيميل للـ Trainee
                $trainee = $mentorshipRequest->trainee;
                if ($trainee) {
                    try {
                        Mail::to($trainee->email)->send(new RequestAccepted($mentorshipRequest));
                        Log::info('Acceptance email sent to trainee', [
                            'request_id' => $mentorshipRequest->id,
                            'trainee_id' => $trainee->User_ID,
                        ]);
                    } catch (\Exception $e) {
                        Log::error('Failed to send Mentorship Request Accepted email', [
                            'request_id' => $mentorshipRequest->id,
                            'trainee_id' => $trainee->User_ID,
                            'error' => $e->getMessage(),
                        ]);
                    }
                } else {
                    Log::warning('Trainee not found for acceptance email', [
                        'request_id' => $mentorshipRequest->id,
                        'trainee_id' => $mentorshipRequest->trainee_id,
                    ]);
                }

                // ملاحظة: الجلسات هتتولد بعد الدفع في completePayment (للـ Group Mentorship)
                // أو في scheduleSessions (للـ Mentorship Plan)
            }

            // لو الطلب بقى cancelled (مثلاً بسبب عدم الدفع أو أي سبب آخر)
            if ($newStatus === 'cancelled') {
                // إلغاء أي جلسات مرتبطة
                NewSession::where('mentorship_request_id', $mentorshipRequest->id)
                    ->whereIn('status', ['pending', 'upcoming'])
                    ->update(['status' => 'cancelled']);

                // إذا كان GroupMentorship، إزالة الـ Trainee من القائمة
                if ($mentorshipRequest->requestable_type === \App\Models\GroupMentorship::class) {
                    $groupMentorship = $mentorshipRequest->requestable;
                    if ($groupMentorship) {
                        $groupMentorship->removeTrainee($mentorshipRequest->trainee_id);
                        Log::info('Trainee removed from GroupMentorship due to cancellation', [
                            'request_id' => $mentorshipRequest->id,
                            'group_mentorship_id' => $groupMentorship->id,
                            'trainee_id' => $mentorshipRequest->trainee_id,
                        ]);
                    }
                }

                // ملاحظة: ممكن نضيف إيميل للـ Trainee هنا لو عايزين نعرفهم إن الطلب اتلغى
            }
        }
    }
}
