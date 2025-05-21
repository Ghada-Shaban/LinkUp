<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Coach;
use App\Models\User;

class CoachAccepted extends Mailable
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
        return $this->subject('🎉 Congratulations! Your Coach Registration Has Been Accepted')
                    ->view('emails.coach_accepted');
    }
}
