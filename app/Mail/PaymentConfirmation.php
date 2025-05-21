<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\MentorshipRequest;
use App\Models\NewSession;
use App\Models\Payment;

class PaymentConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public $mentorshipRequest;
    public $sessions;
    public $payment;

    public function __construct($mentorshipRequest, $sessions, $payment)
    {
        $this->mentorshipRequest = $mentorshipRequest;
        $this->sessions = $sessions;
        $this->payment = $payment;
    }

    public function build()
    {
        return $this->subject('✅ Payment Confirmed & Session Schedule')
                    ->view('emails.payment_confirmation');
    }
}
