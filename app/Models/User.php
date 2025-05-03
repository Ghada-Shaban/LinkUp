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
        'Role_Profile',
        'Photo_Public_ID'
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
     'photo_url',
    ];
   // تعيين خريطة بين أسماء الحقول في النموذج وأسماء الأعمدة في قاعدة البيانات
    public function getAttributeMap()
    {
        return [
            'Full_Name' => 'full_name',
            'Email' => 'email',
            'Password' => 'password',
            'Linkedin_Link' => 'linkedin_link',
            'Photo' => 'photo',
            'Role_Profile' => 'role_profile',
            // الحقول الأخرى تبقى كما هي
        ];
    }

    /**
     * تجاوز دالة الحصول على قيمة الحقل لاستخدام الاسم الصحيح في قاعدة البيانات
     */
    public function getAttribute($key)
    {
        $map = $this->getAttributeMap();
        
        if (array_key_exists($key, $map)) {
            return parent::getAttribute($map[$key]);
        }
        
        return parent::getAttribute($key);
    }

    /**
     * تجاوز دالة تعيين قيمة الحقل لاستخدام الاسم الصحيح في قاعدة البيانات
     */
    public function setAttribute($key, $value)
    {
        $map = $this->getAttributeMap();
        
        if (array_key_exists($key, $map)) {
            return parent::setAttribute($map[$key], $value);
        }
        
        return parent::setAttribute($key, $value);
    }
    
    /**
     * تجاوز الدالة التي تحدد وجود الخاصية
     */
    public function __isset($key)
    {
        $map = $this->getAttributeMap();
        
        if (array_key_exists($key, $map)) {
            return parent::__isset($map[$key]);
        }
        
        return parent::__isset($key);
    }  // أضف هذه الدالة داخل class User مباشرة
public function getPhotoUrlAttribute()
{
    return $this->photo ? Storage::url($this->photo) : null;
}

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

    // إضافة العلاقة مع جدول services
    public function services()
    {
        return $this->hasMany(Service::class, 'coach_id', 'User_ID');
    }

    // إضافة العلاقة مع جدول coach_skills
    public function skills()
    {
        return $this->hasMany(CoachSkill::class, 'coach_id', 'User_ID');
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
    public function requests()
    {
        return $this->morphMany(MentorshipRequest::class, 'requestable');
    }
}
