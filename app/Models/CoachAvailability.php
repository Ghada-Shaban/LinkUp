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
        'date',
        'start_time',
        'end_time',
        'is_booked',
    ];
    public function coach()
    {
        return $this->belongsTo(Coach::class, 'User_ID', 'User_ID');
    }
}
