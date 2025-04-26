<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CoachAvailability extends Model
{
    use HasFactory;
    protected $table = 'coach_available_times';
   protected $fillable = [
        'coach_id',
        'Day_Of_Week',
        'Start_Time',
        'End_Time',
    ];
    public function coach()
    {
        return $this->belongsTo(Coach::class, 'coach_id', 'User_ID');
    }
}
