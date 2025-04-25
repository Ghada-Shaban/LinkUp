<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\MentorshipRequest;

class PaymentReminder extends Mailable
{
    use Queueable, SerializesModels;

    public $mentorshipRequest;

    public function __construct(MentorshipRequest $mentorshipRequest)
    {
        $this->mentorshipRequest = $mentorshipRequest;
    }

    public function build()
    {
        return $this->subject('Payment Reminder for Your Mentorship Request')
                    ->view('emails.payment_reminder')
                    ->with([
                        'request' => $this->mentorshipRequest,
                    ]);
    }
}
</xai
