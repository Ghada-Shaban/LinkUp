<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use HasFactory;
    use SoftDeletes;
    protected $table = 'services';
    protected $primaryKey = 'service_id';
    public $incrementing = true;

    protected $fillable = [
        'service_type',
        'admin_id',
        'coach_id',
        // أي حقول أخرى قد تحتاجها
    ];

    // إضافة العلاقات المفقودة
    public function user()
    {
        return $this->belongsTo(User::class, 'coach_id', 'User_ID');
    }

    public function groupMentorship()
    {
        return $this->hasOne(GroupMentorship::class, 'service_id');
    }

    public function price()
    {
        return $this->hasOne(Price::class, 'service_id');
    }

    // الحفاظ على العلاقات الموجودة
    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    public function mentorship()
    {
        return $this->hasOne(Mentorship::class, 'service_id');
    }

    public function projectEvaluation()
    {
        return $this->hasOne(ProjectEvaluation::class, 'service_id');
    }

    public function cvReview()
    {
        return $this->hasOne(CVReview::class, 'service_id');
    }

    public function linkedinOptimization()
    {
        return $this->hasOne(LinkedinOptimization::class, 'service_id');
    }

    public function mockInterview()
    {
        return $this->hasOne(MockInterview::class, 'service_id');
    }
    
    public function sessions()
    {
        return $this->hasMany(NewSession::class, 'service_id');
    }
}
