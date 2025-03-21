<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public $otp;

    /**
     * Create a new message instance.
     *
     * @param int $otp
     */
    public function __construct($otp)
    {
        $this->otp = $otp;
    }

    /**
     * Build the email message.
     *
     * @return $this
     */
    public function build()
    {
        if (is_null($this->otp)) {
            throw new \Exception('OTP is missing.'); // حماية إضافية عند الحاجة
        }

        return $this->subject('Password Reset OTP')
                    ->view('emails.reset-password')
                    ->with(['otp' => $this->otp]);
    }
}