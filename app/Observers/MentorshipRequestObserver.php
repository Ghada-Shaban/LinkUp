<?php

namespace App\Observers;

use App\Models\MentorshipRequest;
use App\Models\NewSession;
use App\Models\GroupMentorship;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class MentorshipRequestObserver
{
    /**
     * Handle the MentorshipRequest "created" event.
     */
    public function created(MentorshipRequest $mentorshipRequest)
    {
        // Log the creation of the new request
        $coach = User::find($mentorshipRequest->coach_id);
        if ($coach) {
            Log::info('New mentorship request created', [
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
            }

            // لو الطلب بقى accepted
            if ($newStatus === 'accepted' && $oldStatus === 'pending') {
                // ملاحظة: الجلسات هتتولد بعد الدفع في completePayment (للـ Group Mentorship)
                // أو في scheduleSessions (للـ Mentorship Plan)
                Log::info('Mentorship request accepted', [
                    'request_id' => $mentorshipRequest->id,
                    'trainee_id' => $mentorshipRequest->trainee_id,
                ]);
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
            }
        }
    }
}
