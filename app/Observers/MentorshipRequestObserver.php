<?php

namespace App\Observers;

use App\Models\MentorshipRequest;
use App\Models\NewSession;
use App\Models\GroupMentorship;
use Illuminate\Support\Facades\Log;
use App\Mail\RequestAccepted;
use App\Mail\RequestRejected;
use Illuminate\Support\Facades\Mail;

class MentorshipRequestObserver
{
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
                    }
                }

                // إرسال إيميل للـ Trainee
                $trainee = $mentorshipRequest->trainee;
                if ($trainee) {
                    Mail::to($trainee->email)->send(new RequestRejected($mentorshipRequest));
                }
            }

            // لو الطلب بقى accepted
            if ($newStatus === 'accepted' && $oldStatus === 'pending') {
                // إرسال إيميل للـ Trainee
                $trainee = $mentorshipRequest->trainee;
                if ($trainee) {
                    Mail::to($trainee->email)->send(new RequestAccepted($mentorshipRequest));
                }

                // لو GroupMentorship، الجلسة هتتولد بعد الدفع في completePayment
                // لو MentorshipPlan، الجلسات هتتولد في scheduleSessions
                // يعني مفيش حاجة هنا دلوقتي
            }

            // لو الطلب بقى cancelled (مثلاً بسبب عدم الدفع)
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
                    }
                }
            }
        }
    }
}
