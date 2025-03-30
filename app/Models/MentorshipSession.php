<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MentorshipSession extends Model
{ 
    use HasFactory;
    protected $table = 'mentorship_sessions';

    protected $primaryKey = 'service_id';
    protected $fillable = ['service_id', 'session_type'];

    public function mentorship()
    {
        return $this->belongsTo(Mentorship::class, 'service_id', 'service_id');
    }
}