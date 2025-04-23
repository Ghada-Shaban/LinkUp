<?php

namespace App\Mail;

use App\Models\MentorshipRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RequestAccepted extends Mailable
{
    use Queueable, SerializesModels;

    public $mentorshipRequest;

    public function __construct(MentorshipRequest $mentorshipRequest)
    {
        $this->mentorshipRequest = $mentorshipRequest;
    }

    public function build()
    {
        return $this->subject('Your Mentorship Request Has Been Accepted')
                    ->view('emails.request_accepted')
                    ->with([
                        'request' => $this->mentorshipRequest,
                        'session_time' => $this->mentorshipRequest->first_session_time,
                        'title' => $this->mentorshipRequest->title,
                        'type' => $this->mentorshipRequest->type,
                        'plan_schedule' => $this->mentorshipRequest->plan_schedule,
                    ]);
    }
}
