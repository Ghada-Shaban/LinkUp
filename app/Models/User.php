<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasProfilePhoto;
    use Notifiable;
    use TwoFactorAuthenticatable;

    protected $fillable = [
        'Full_Name', 
        'Email',
        'Password',
        'Linkedin_Link',
        'Photo',
        'Role_Profile'
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    public function coach() { return $this->hasOne(Coach::class, 'User_ID'); }
    public function trainee() { return $this->hasOne(Trainee::class, 'User_ID');}


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


    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $primaryKey = 'User_ID';
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

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
