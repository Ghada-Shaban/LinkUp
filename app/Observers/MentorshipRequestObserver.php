<?php

namespace App\Observers;

use App\Models\MentorshipRequest;
use App\Models\NewSession;

class MentorshipRequestObserver
{
    /**
     * Handle the MentorshipRequest "updated" event.
     */
    public function updated(MentorshipRequest $mentorshipRequest): void
    {
        // لما الـ status يتغير في mentorship_requests
        $newSession = NewSession::where('mentorship_request_id', $mentorshipRequest->id)->first();

        if ($newSession) {
            switch ($mentorshipRequest->status) {
                case 'accepted':
                    $newSession->status = 'Scheduled';
                    break;
                case 'rejected':
                    $newSession->status = 'Cancelled'; // أو ممكن تحذفه لو عايز
                    break;
                case 'pending':
                    $newSession->status = 'Pending';
                    break;
                case 'completed':
                    $newSession->status = 'Completed';
                    break;
            }
            $newSession->save();
        }
    }
}