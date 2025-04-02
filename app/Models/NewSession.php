<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NewSession extends Model
{
    use HasFactory;
    
    protected $table = 'new_sessions';

    protected $fillable = [
        'date_time',
        'duration',
        'status',
        'service_id',
        'meeting_link', // عشان نقدر نحفظ اللينك
    ];

    // تعريف الحالات الممكنة للـ status كثوابت (Constants)
    const STATUS_PENDING = 'Pending';
    const STATUS_SCHEDULED = 'Scheduled';
    const STATUS_COMPLETED = 'Completed'; // اختياري، لو عايز تضيف حالات تانية زي "مكتملة"
    const STATUS_CANCELLED = 'Cancelled'; // اختياري، لو عايز حالة إلغاء

    /**
     * الـ Default Attributes
     */
    protected $attributes = [
        'status' => self::STATUS_PENDING, // الجلسة هتبدأ دايمًا كـ Pending
    ];

    /**
     * علاقة مع الـ Service
     */
    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id');
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
