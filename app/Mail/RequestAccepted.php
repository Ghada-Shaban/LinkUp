<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MentorshipRequestAccepted extends Mailable
{
    use Queueable, SerializesModels;

    public $mentorshipRequest;

    public function __construct($mentorshipRequest)
    {
        $this->mentorshipRequest = $mentorshipRequest;
    }

    public function build()
    {
        return $this->subject('Mentorship Request Accepted')
                    ->view('emails.mentorship-request-accepted');
    }
}
