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
       
    
    ];

    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id', 'service_id');
    }
public function getAvailableSlotsAttribute()
    {
        return $this->max_participants - $this->current_participants;
    }
    
}

