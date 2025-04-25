<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MentorshipPlan extends Model
{ 
    use HasFactory;

    protected $table = 'mentorship_plans';
    public $timestamps = false;

    protected $primaryKey = 'service_id';
    protected $fillable = ['service_id', 'title'];

    public function mentorship()
    {
        return $this->belongsTo(Mentorship::class, 'service_id', 'service_id');
    }
    public function requests()
    {
        return $this->morphMany(MentorshipRequest::class, 'requestable');
    }
    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id');
    }
    public function coach()
    {
        return $this->service->coach();
    }
}
