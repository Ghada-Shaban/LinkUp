<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $table = 'payments';
    protected $primaryKey = 'payment_id';

    protected $fillable = [
        'mentorship_request_id',
        'amount',
        'payment_method',
        'payment_status',
        'date_time',
    ];

    protected $casts = [
        'date_time' => 'datetime',
        'amount' => 'decimal:2',
    ];

    // علاقة مع MentorshipRequest
    public function mentorshipRequest()
    {
        return $this->belongsTo(MentorshipRequest::class, 'mentorship_request_id', 'id');
    }
     public function session()
    {
        return $this->belongsTo(NewSession::class, 'session_id', 'new_session_id');
    }

   // public function service()
   //  {
   //      return $this->belongsTo(Service::class, 'service_id', 'service_id');
   //  }
}
