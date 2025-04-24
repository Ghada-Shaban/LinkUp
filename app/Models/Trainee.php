<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


    class Trainee extends Model
    {
        use HasFactory;
    
    protected $primaryKey = 'User_ID';
    public $incrementing = false;

    protected $fillable = [
        'User_ID',
        'Education_Level',
        'Institution_Or_School',
        'Field_Of_Study',
        'Current_Role',
        'Story',
        'Years_Of_Professional_Experience'
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'User_ID');
    }

    public function preferredLanguages()
    {
        return $this->hasMany(TraineePreferredLanguage::class,'trainee_id' ,'User_ID');
    }

    public function areasOfInterest()
    {
        return $this->hasMany(TraineeAreaOfInterest::class, 'trainee_id','User_ID');

    }

    public function bookedSessions()
    {
        return $this->belongsToMany(NewSession::class, 'books', 'trainee_id', 'new_session_id');
    }

    public function reviews()
    {
        return $this->hasMany(Review::class, 'trainee_id', 'User_ID');
    }

    public function performanceReports()
    {
        return $this->hasMany(EmailPerformanceReport::class, 'trainee_id', 'User_ID');
    }
    public function mentorshipRequests()
    {
        return $this->hasMany(MentorshipRequest::class, 'trainee_id', 'User_ID');
    }
}
