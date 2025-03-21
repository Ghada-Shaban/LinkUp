<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailPerformanceReport extends Model
{
    use HasFactory;
    protected $table = 'email_performance_reports';
    public $timestamps = false; 
    protected $fillable = [
        'strengths',
        'areas_for_improvement',
        'rating',
        'development_plan',
        'comments',
        'email_feedback_status',
        'new_session_id',
        'trainee_id'
    ];
    public function session()
    {
        return $this->belongsTo(NewSession::class,'new_session_id');
    }

    public function trainee()
    {
        return $this->belongsTo(Trainee::class, 'trainee_id', 'user_id');
    }

    protected $casts = [
        'rating' => 'integer', 
        'email_feedback_status' => 'boolean' 
    ];
}
