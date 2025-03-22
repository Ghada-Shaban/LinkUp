<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CoachAvailability extends Model
{
    use HasFactory;
    protected $table = 'coach_available_times';
    protected $fillable = [
        'User_ID',
        'Day_Of_Week',
        'Start_Time',
        'End_Time',
    ];
    public function coach()
    {
        return $this->belongsTo(coach::class, 'coach_id', 'User_ID');
    }
}
