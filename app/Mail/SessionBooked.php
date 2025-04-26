<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SessionBooked extends Mailable
{
    use Queueable, SerializesModels;

    public $entity;

    public function __construct($entity)
    {
        $this->entity = $entity;
    }

    public function build()
    {
        return $this->subject('Session Booked Successfully')
                    ->view('emails.session_booked');
    }
}
