<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\MentorshipRequest;

class SessionBooked extends Mailable
{
    use Queueable, SerializesModels;

    public $mentorshipRequest;

    public function __construct(MentorshipRequest $mentorshipRequest)
    {
        $this->mentorshipRequest = $mentorshipRequest;
    }

    public function build()
    {
        return $this->subject('Session Booked Successfully')
                    ->view('emails.session_booked')
                    ->with([
                        'request' => $this->mentorshipRequest,
                    ]);
    }
}
