<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Mentorship extends Model
{
    use HasFactory;
    protected $table = 'mentorships';
    protected $primaryKey = "service_id";
    public $timestamps = false;


    protected $fillable = [
        'service_id',
        'mentorship_type'
    ];
    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id', 'service_id');
    }

    public function mentorshipPlan()
    {
        return $this->hasOne(MentorshipPlan::class, 'service_id', 'service_id');
    }

    public function mentorshipSession()
    {
        return $this->hasOne(MentorshipSession::class, 'service_id', 'service_id');
    }
    
}
