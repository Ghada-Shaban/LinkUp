<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TraineeAreaOfInterest extends Model
{
    use HasFactory;
    protected $table = 'trainee_areas_of_interest';
    
    protected $fillable = [
        'trainee_id',
        'Area_Of_Interest'
    ];
    public $timestamps = false;

    public function trainee()
    {
        return $this->belongsTo(Trainee::class, 'trainee_id', 'User_ID');
    }
}
