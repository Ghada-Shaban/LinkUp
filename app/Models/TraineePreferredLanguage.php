<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TraineePreferredLanguage extends Model
{
    use HasFactory;
    protected $table = 'trainee_preferred_languages';
    
    protected $fillable = [
        'trainee_id',
        'Language'
    ];
    public $timestamps = false;

    public function trainee()
    {
        return $this->belongsTo(Trainee::class, 'trainee_id', 'User_ID');
    }
}
