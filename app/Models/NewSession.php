<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NewSession extends Model
{
    use HasFactory;
    
    protected $table = 'new_sessions';
    protected $primaryKey = 'new_session_id';

    protected $fillable = [
        'new_session_id',
        'date_time',
        'duration',
        'status',
        'payment_status',
        'service_id',
        'meeting_link',
        'coach_id',
        'trainee_id',
        'mentorship_request_id',
    ];

    protected $casts = [
        'date_time' => 'datetime',
        'duration' => 'integer',
        'service_id' => 'integer',
        'coach_id' => 'integer',
        'trainee_id' => 'integer',
        'mentorship_request_id' => 'integer',
    ];

    const STATUS_PENDING = 'Pending';
    const STATUS_SCHEDULED = 'Scheduled';
    const STATUS_COMPLETED = 'Completed';
    const STATUS_CANCELLED = 'Cancelled';

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function mentorshipRequest()
    {
        return $this->belongsTo(MentorshipRequest::class, 'mentorship_request_id', 'id');
    }

    public function trainees()
    {  
        return $this->belongsToMany(  
            User::class,
            'books',  
            'session_id',  
            'trainee_id'  
        )->where('role_profile', 'Trainee');
    }

    public function attendees()
    {
        return $this->belongsToMany(
            User::class,
            'attends',
            'session_id',
            'user_id'
        );
    }

    public function books()
    {
        return $this->hasMany(Book::class, 'session_id', 'new_session_id');
    }

    public function coach()
    {
        return $this->belongsTo(User::class, 'coach_id', 'User_ID'); // نزلنا الشرط where('role_profile', 'Coach')
    }

    public function isPending()
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isScheduled()
    {
        return $this->status === self::STATUS_SCHEDULED;
    }
}
