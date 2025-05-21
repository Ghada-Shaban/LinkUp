<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\MentorshipRequest;
use App\Models\NewSession;

class PaymentConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public $mentorshipRequest;
    public $sessions;

    public function __construct(MentorshipRequest $mentorshipRequest, $sessions)
    {
        $this->mentorshipRequest = $mentorshipRequest;
        $this->sessions = $sessions;
    }

    public function build()
    {
        return $this->subject('✅ Payment Confirmed & Session Schedule')
                    ->view('emails.payment_confirmation');
    }
}
