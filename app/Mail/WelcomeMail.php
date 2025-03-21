<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;

    /**
     * Create a new message instance.
     */
    public function __construct($user)
    {
        $this->user = $user;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $subject = 'Welcome to LinkUp!';
        
        return $this->subject($subject)
                    ->view('emails.welcome')
                    ->with([
                        'name' => $this->user->Full_Name, // تم تصحيح الاسم
                        'role' => $this->user->Role_Profile,
                        'status' => $this->user->Role_Profile === 'Coach' ? 'Pending Approval' : 'Active'
                    ]);
    }
}
