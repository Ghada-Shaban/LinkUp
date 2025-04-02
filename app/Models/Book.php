<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Book extends Model
{
    use HasFactory;

    protected $table = 'books';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['trainee_id', 'session_id'];

    // تعديل العلاقة مع الـ Trainee ليتم الربط مع User وليس Trainee
    public function trainee()
    {
        return $this->belongsTo(User::class, 'trainee_id', 'User_ID')
                    ->where('role_profile', 'Trainee'); // نجيب بس المستخدمين اللي هم Trainees
    }

    public function session()
    {
        return $this->belongsTo(NewSession::class, 'session_id', 'new_session_id');
    }
}