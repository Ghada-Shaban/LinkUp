<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Observers\MentorshipRequestObserver;

class MentorshipRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'requestable_id',
        'requestable_type',
        'trainee_id',
        'coach_id',
        'status',
    ];

    public function requestable()
    {
        return $this->morphTo();
    }

    public function trainee()
    {
        return $this->belongsTo(User::class, 'trainee_id', 'User_ID');
    }

    public function coach()
    {
        return $this->belongsTo(User::class, 'coach_id', 'User_ID');
    }

    protected static function boot()
    {
        parent::boot();

        static::observe(MentorshipRequestObserver::class);
    }
}
