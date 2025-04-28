<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MentorshipRequest extends Model
{
    use HasFactory;

    protected $table = 'mentorship_requests';
    protected $primaryKey = 'id';

    protected $fillable = [
        'trainee_id',
        'coach_id',
        'requestable_type',
        'requestable_id',
        'status',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_REJECTED = 'rejected';

    /**
     * الـ Default Attributes
     */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    /**
     * علاقة مع الـ Requestable (Polymorphic)
     */
    public function requestable()
    {
        return $this->morphTo();
    }

    /**
     * علاقة مع الـ Trainee
     */
    public function trainee()
    {
        return $this->belongsTo(User::class, 'trainee_id', 'User_ID')
            ->where('role_profile', 'Trainee');
    }

    /**
     * علاقة مع الـ Coach
     */
    public function coach()
    {
        return $this->belongsTo(User::class, 'coach_id', 'User_ID')
            ->where('role_profile', 'Coach');
    }

    /**
     * دالة مساعدة للتحقق إذا كان الطلب Pending
     */
    public function isPending()
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * دالة مساعدة للتحقق إذا كان الطلب Accepted
     */
    public function isAccepted()
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    /**
     * تعديل شكل البيانات عند تحويل الموديل لـ Array/JSON
     */
    public function toArray()
    {
        $array = parent::toArray();

        if ($this->requestable_type === GroupMentorship::class) {
            $array['service_type'] = 'Group Mentorship';
        } elseif ($this->requestable_type === MentorshipPlan::class) {
            $array['service_type'] = 'Group Mentorship Plan';
        }

        unset($array['requestable_type']);
        return $array;
    }
}
