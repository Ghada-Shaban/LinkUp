<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasProfilePhoto, Notifiable, TwoFactorAuthenticatable;

    protected $fillable = [
        'Full_Name', 
        'Email',
        'Password',
        'Linkedin_Link',
        'Photo',
        'Role_Profile'
    ];

    protected $primaryKey = 'User_ID';

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    protected $appends = [
        'profile_photo_url',
    ];

    public function coach() { 
        return $this->hasOne(Coach::class, 'User_ID'); 
    }

    public function trainee() { 
        return $this->hasOne(Trainee::class, 'User_ID');
    }

    public function reviewsAsTrainee()
    {
        return $this->hasMany(Review::class, 'trainee_id', 'User_ID');
    }

    public function reviewsAsCoach()
    {
        return $this->hasMany(Review::class, 'coach_id', 'User_ID');
    }

    public function attendedSessions()
    {
        return $this->belongsToMany(NewSession::class, 'attends', 'User_ID', 'new_session_id');
    }

    public function viewedServices()
    {
        return $this->belongsToMany(Service::class, 'views', 'User_ID', 'service_id');
    }

    public function emailNotifications()
    {
        return $this->belongsToMany(EmailNotification::class, 'received_by', 'User_ID', 'email_notification_id');
    }

    public function bookings()
    {
        return $this->hasMany(Book::class, 'trainee_id', 'User_ID');
    }
    
    public function coachedSessions()
    {
        return $this->hasMany(NewSession::class, 'coach_id', 'User_ID')
                    ->where('role_profile', 'Coach');
    }

    public function confirmedCoachingSessionsCount()
    {
        return $this->hasMany(NewSession::class, 'coach_id', 'User_ID')
                    ->where('status', 'Scheduled')
                    ->count();
    }

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($user) {
            if ($user->Photo) {
                Storage::disk('public')->delete($user->Photo);
            }
        });
    }

}
