<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerformanceReport extends Model
{
    protected $table = 'performance_reports';
    protected $fillable = [
        'session_id', 'coach_id', 'trainee_id', 'overall_rating', 'strengths', 'weaknesses', 'comments'
    ];
    protected $hidden = ['created_at', 'updated_at'];
    public function session()
    {
        return $this->belongsTo(NewSession::class, 'session_id', 'new_session_id');
    }

    public function coach()
    {
        return $this->belongsTo(User::class, 'coach_id', 'User_ID');
    }

    public function trainee()
    {
        return $this->belongsTo(User::class, 'trainee_id', 'User_ID');
    }
}
