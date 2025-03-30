<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use HasFactory;
    protected $table = 'services';
    public $incrementing = true;
    protected $primaryKey ='service_id';
    protected $fillable = [
        'service_type',
        'admin_id'
    ];
    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
    public function mentorship() {
        return $this->hasOne(Mentorship::class, 'service_id');
    }

    public function mockInterview() {
        return $this->hasOne(MockInterview::class, 'service_id');
    }

    public function groupMentorship() {
        return $this->hasOne(GroupMentorship::class, 'service_id');
    }

    public function prices() {
        return $this->hasMany(Price::class, 'service_id');
    }
    public function coaches() {
        return $this->belongsToMany(Coach::class, 'chooses', 'service_id', 'coach_id');
    }
 public function sessions()
    {
        return $this->hasMany(NewSession::class, 'service_id');

    }

   
    
}
