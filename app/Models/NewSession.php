<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NewSession extends Model
{
    use HasFactory;
    
    protected $table = 'new_sessions';
    protected $primaryKey = 'new_session_id'; // الـ primary key الصحيح

    protected $fillable = [
        'new_session_id', // أضفناه عشان يتعامل مع الـ primary key
        'date_time',
        'duration',
        'status',
        'service_id',
        'meeting_link',
        'coach_id', // أضفناه
        'trainee_id', // أضفناه
        'mentorship_request_id', // أضفناه عشان الربط مع MentorshipRequest
    ];

    protected $casts = [
        'date_time' => 'datetime',
        'duration' => 'integer',
        'service_id' => 'integer',
        'coach_id' => 'integer',
        'trainee_id' => 'integer',
        'mentorship_request_id' => 'integer',
    ];

    // تعريف الحالات الممكنة للـ status كثوابت (Constants)
    const STATUS_PENDING = 'Pending';
    const STATUS_SCHEDULED = 'Scheduled';
    const STATUS_COMPLETED = 'Completed';
    const STATUS_CANCELLED = 'Cancelled';

    /**
     * الـ Default Attributes
     */
    protected $attributes = [
        'status' => self::STATUS_PENDING, // الجلسة هتبقى دايمًا كـ Pending
    ];

    /**
     * علاقة مع الـ Service
     */
    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    /**
     * علاقة مع الـ MentorshipRequest
     */
    public function mentorshipRequest()
    {
        return $this->belongsTo(MentorshipRequest::class, 'mentorship_request_id', 'id');
    }

    /**
     * علاقة مع الـ Trainees (المتدربين)
     */
    public function trainees()
    {  
        return $this->belongsToMany(  
            User::class,  // هنربط بالـ User لأنه هو اللي عنده الـ role_profile كـ Trainee
            'books',  
            'session_id',  
            'trainee_id'  
        )->where('role_profile', 'Trainee'); // هنجيب بس اللي الـ role بتاعه Trainee
    }

    /**
     * علاقة مع الـ Attendees (الحاضرين)
     */
    public function attendees()
    {
        return $this->belongsToMany(
            User::class,
            'attends',
            'session_id',
            'user_id'
        );
    }

    /**
     * علاقة مع الـ Books (الحجوزات)
     */
    public function books()
    {
        return $this->hasMany(Book::class, 'session_id', 'new_session_id');
    }

    /**
     * علاقة مع الـ Coach
     */
    public function coach()
    {
        return $this->belongsTo(User::class, 'coach_id', 'User_ID')
            ->where('role_profile', 'Coach'); // الكوتش برضه جزء من الـ Users
    }

    /**
     * دالة مساعدة للتحقق إذا كانت الجلسة Pending
     */
    public function isPending()
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * دالة مساعدة للتحقق إذا كانت الجلسة Scheduled
     */
    public function isScheduled()
    {
        return $this->status === self::STATUS_SCHEDULED;
    }
}
