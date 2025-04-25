<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\GroupMentorship;
use App\Models\MentorshipPlan;

class MentorshipRequest extends Model
{
    protected $fillable = [
        'requestable_id', 
        'requestable_type', 
        'trainee_id', 
        'coach_id', 
        'status', 
        'responded_at',
        'first_session_time',
        'duration_minutes',
        'plan_schedule',
        // 'payment_due_at',
    ];

    protected $casts = [
        'plan_schedule' => 'array',
        // 'payment_due_at' => 'datetime',
    ];

    public function requestable()
    {
        return $this->morphTo();
    }
    
    public function coach()
    {
        return $this->belongsTo(Coach::class, 'coach_id', 'User_ID');
    }
    
    public function trainee()
    {
        return $this->belongsTo(Trainee::class, 'trainee_id', 'User_ID');
    }

    public function toArray()
    {
        $array = parent::toArray();

        if ($this->requestable_type === GroupMentorship::class) {
            $array['service_type'] = 'Group Mentorship';
        } elseif ($this->requestable_type === MentorshipPlan::class) {
            $array['service_type'] = 'Mentorship Plan';
        }

        unset($array['requestable_type']); 
        return $array;
    }
}
