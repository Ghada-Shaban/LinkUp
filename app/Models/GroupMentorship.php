<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GroupMentorship extends Model
{
    use HasFactory;
    protected $table = 'group_mentorships';
    public $timestamps = false;

    protected $primaryKey = "service_id";
    protected $fillable = [
        'service_id', 
        'title', 
        'description', 
        'day',
        'start_time',
        'available_slots',
        'current_participants',
        'trainee_ids',
    ];

    protected $casts = [
        'trainee_ids' => 'array',
    ];

    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id', 'service_id');
    }

    public function getAvailableSlotsAttribute()
    {
        return $this->max_participants - $this->current_participants;
    }

    public function requests()
    {
        return $this->morphMany(MentorshipRequest::class, 'requestable');
    }

    public function coach()
    {
        return $this->service->coach();
    }

    public function addTrainee($traineeId)
    {
        $traineeIds = $this->trainee_ids ?? [];
        if (!in_array($traineeId, $traineeIds)) {
            $traineeIds[] = $traineeId;
            $this->trainee_ids = $traineeIds;
            $this->increment('current_participants');
            $this->save();
        }
    }

    public function removeTrainee($traineeId)
    {
        $traineeIds = $this->trainee_ids ?? [];
        if (($key = array_search($traineeId, $traineeIds)) !== false) {
            unset($traineeIds[$key]);
            $this->trainee_ids = array_values($traineeIds);
            $this->decrement('current_participants');
            $this->save();
        }
    }
}


