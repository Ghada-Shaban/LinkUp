<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\MentorshipRequest;

class GroupMentorshipRequestAccepted extends Mailable
{
    use Queueable, SerializesModels;

    public $mentorshipRequest;

    public function __construct(MentorshipRequest $mentorshipRequest)
    {
        $this->mentorshipRequest = $mentorshipRequest;
    }

    public function build()
    {
        return $this->subject('Group Mentorship Request Accepted')
                    ->view('emails.group_mentorship_request_accepted');
    }
}
