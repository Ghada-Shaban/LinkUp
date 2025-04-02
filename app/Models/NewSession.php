<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NewSession extends Model
{
    use HasFactory;
    
    protected $table = 'new_sessions';

    protected $fillable = [
        'date_time',
        'duration',
        'status',
        'service_id',
        'meeting_link' // عشان نقدر نحفظ اللينك
    ];

    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function trainees()  
    {  
        return $this->belongsToMany(  
            User::class,  // هنربط بالـ User لأنه هو اللي عنده الـ role_profile كـ Trainee
            'books',  
            'session_id',  
            'trainee_id'  
        )->where('role_profile', 'Trainee'); // هنجيب بس اللي الـ role بتاعه Trainee
    }

    public function attendees()
    {
        return $this->belongsToMany(
            User::class,
            'attends',
            'session_id',
            'user_id'
        );
    }

    public function books()
    {
        return $this->hasMany(Book::class, 'session_id', 'new_session_id');
    }

    public function coach()
    {
        return $this->belongsTo(User::class, 'coach_id', 'User_ID')
            ->where('role_profile', 'Coach'); // الكوتش برضه جزء من الـ Users
    }
}