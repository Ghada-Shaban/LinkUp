<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MentorshipPlan extends Model
{ 
    use HasFactory;

    protected $table = 'mentorship_plans';
    protected $primaryKey = 'service_id';
    protected $fillable = ['service_id', 'title'];

    public function mentorship()
    {
        return $this->belongsTo(Mentorship::class, 'service_id', 'service_id');
    }
}