<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Coach extends Model
{
    use HasFactory;
    use HasApiTokens;

    protected $primaryKey = 'User_ID';
    public $incrementing = false;

    protected $fillable = [
        'User_ID', 'Title', 'Company_or_School',
        'Bio', 'admin_id', 'Years_Of_Experience', 'Months_Of_Experience',
        'status',
    ];

    public function skills()
    {
        return $this->hasMany(CoachSkill::class, 'coach_id', 'User_ID');
    }

    public function languages()
    {
        return $this->hasMany(CoachLanguage::class, 'coach_id', 'User_ID');
    }

    public function admin()
    {
        return $this->belongsTo(Admin::class);
    }
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    public function user()
    {
        return $this->belongsTo(User::class, 'User_ID');
    }

    public function services()
    {
        return $this->belongsToMany(Service::class, 'chooses', 'coach_id', 'service_id')
                    ->withPivot('coach_id', 'service_id');
    }

  
    public function servicesDirect()
    {
        return $this->hasMany(Service::class, 'coach_id', 'User_ID');
    }

    public function reviews()
    {
        return $this->hasMany(Review::class, 'coach_id', 'User_ID');
    }

    public function sessions()
    {
        return $this->hasMany(NewSession::class, 'coach_id', 'User_ID'); 
    }

    public function availableTimes()
    {
        return $this->hasMany(CoachAvailability::class, 'coach_id', 'User_ID');
    }

    public function getAverageRatingAttribute()
    {
        return round($this->reviews()->avg('Rating'), 2);
    }

    protected $appends = ['average_rating'];
    public function requests()
    {
        return $this->morphMany(MentorshipRequest::class, 'requestable');
    }
    public function mentorshipRequests()
    {
        return $this->hasMany(MentorshipRequest::class, 'coach_id', 'User_ID');
    }
    public function performanceReports()
{
    return $this->hasMany(PerformanceReport::class, 'coach_id', 'User_ID');
}
}
