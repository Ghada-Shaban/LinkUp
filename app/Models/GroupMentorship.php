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
        'start_time'
       
    
    ];

    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id', 'service_id');
    }
public function getAvailableSlotsAttribute()
    {
        return max(0, $this->max_participants - $this->current_participants);
    }
    
}

