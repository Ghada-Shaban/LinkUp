<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// إضافة use للكلاسات GroupMentorship و MentorshipPlan
use App\Models\GroupMentorship;
use App\Models\MentorshipPlan;

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

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    public function requestable()
    {
        return $this->morphTo();
    }

    
    public function trainee()
    {
        return $this->belongsTo(User::class, 'trainee_id', 'User_ID')
            ->where('role_profile', 'Trainee');
    }

    
    public function coach()
    {
        return $this->belongsTo(User::class, 'coach_id', 'User_ID')
            ->where('role_profile', 'Coach');
    }

    
    public function isPending()
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isAccepted()
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    
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
