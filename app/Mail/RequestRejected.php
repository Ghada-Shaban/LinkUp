<?php

namespace App\Mail;

use App\Models\MentorshipRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RequestRejected extends Mailable
{
    use Queueable, SerializesModels;

    public $mentorshipRequest;

    public function __construct(MentorshipRequest $mentorshipRequest)
    {
        $this->mentorshipRequest = $mentorshipRequest;
    }

    public function build()
    {
        return $this->subject('Your Mentorship Request Has Been Rejected')
                    ->view('emails.request_rejected')
                    ->with([
                        'request' => $this->mentorshipRequest,
                        'title' => $this->mentorshipRequest->title,
                        'type' => $this->mentorshipRequest->type,
                    ]);
    }
}
