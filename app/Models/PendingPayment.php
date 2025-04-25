<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingPayment extends Model
{
    protected $fillable = [
        'mentorship_request_id',
        'payment_due_at',
    ];

    protected $casts = [
        'payment_due_at' => 'datetime',
    ];

    public function mentorshipRequest()
    {
        return $this->belongsTo(MentorshipRequest::class);
    }
}
