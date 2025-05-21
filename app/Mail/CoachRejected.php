<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Coach;
use App\Models\User;

class CoachRejected extends Mailable
{
    use Queueable, SerializesModels;

    public $coach;
    public $user;

    public function __construct(Coach $coach, User $user)
    {
        $this->coach = $coach;
        $this->user = $user;
    }

    public function build()
    {
        return $this->subject('😔 Sorry, Your Coach Registration Has Been Rejected')
                    ->view('emails.coach_rejected');
    }
}
