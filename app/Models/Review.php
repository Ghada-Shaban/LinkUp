<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
 
class Review extends Model
{
    use HasFactory;
    protected $table = 'reviews';
    public $incrementing = false;
    public $timestamps = true;

    protected $fillable = ['trainee_id', 'coach_id', 'rating', 'comment'];

    public function trainee()
    {
        return $this->belongsTo(Trainee::class, 'trainee_id', 'User_ID');
    }

    public function coach()
    {
        return $this->belongsTo(Coach::class, 'coach_id', 'User_ID');
    }

   
}
