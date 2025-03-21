<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use HasFactory;
    protected $table = 'services';
    public $incrementing = true;

    protected $fillable = [
        'cost',
        'service_description',
        'service_type',
        'admin_id'
    ];
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
