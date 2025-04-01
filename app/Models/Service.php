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
        'admin_id', 'coach_id'
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

    public function price() {
        return $this->hasOne(Price::class, 'service_id');
    }
   
 public function sessions()
    {
        return $this->hasMany(NewSession::class, 'service_id');

    }
public function coach()
    {
        return $this->belongsTo(Coach::class, 'coach_id','User_ID');
    }
   
    
}
