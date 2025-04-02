<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MentorshipRequest extends Model
{
    use HasFactory;

    protected $primaryKey = 'id';
    protected $table = 'mentorship_requests';

    protected $fillable = [
        'trainee_id',
        'coach_id',
        'service_id',
        'title',
        'type',
        'status',
        'first_session_time',
        'duration_minutes',
        'plan_schedule',
        'group_mentorship_id',
        'mentorship_plan_id'
    ];

    protected $casts = [
        'first_session_time' => 'datetime',
        'plan_schedule' => 'array',
        'duration_minutes' => 'integer',
        'trainee_id' => 'integer',
        'coach_id' => 'integer',
        'service_id' => 'integer',
        'group_mentorship_id' => 'integer',
        'mentorship_plan_id' => 'integer'
    ];

    protected $dates = [
        'first_session_time',
        'created_at',
        'updated_at'
    ];

    // Relationship to Trainee (using User_ID as primary key in trainees table)
    public function trainee()
    {
        return $this->belongsTo(Trainee::class, 'trainee_id', 'User_ID');
    }

    // Relationship to Coach (using User_ID as primary key in coaches table)
    public function coach()
    {
        return $this->belongsTo(Coach::class, 'coach_id', 'User_ID');
    }

    // Relationship to Service
    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id', 'service_id');
    }

    // Relationship to Group Mentorship
    public function groupMentorship()
    {
        return $this->belongsTo(GroupMentorship::class, 'group_mentorship_id', 'service_id');
    }

    // Relationship to Mentorship Plan
    public function mentorshipPlan()
    {
        return $this->belongsTo(MentorshipPlan::class, 'mentorship_plan_id', 'service_id');
    }

    // Accessor for formatted session time
    public function getFormattedSessionTimeAttribute()
    {
        return $this->first_session_time->format('F j, Y \a\t g:i A');
    }

    // Scope for pending requests
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    // Scope for a specific coach
    public function scopeForCoach($query, $coachId)
    {
        return $query->where('coach_id', $coachId);
    }
}