<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attend extends Model
{
    use HasFactory;
    
    protected $table = 'attends';

    protected $fillable = [
        'user_id',
        'session_id'
    ];

    public function user()  
{  
    return $this->belongsTo(User::class, 'User_ID');  
}  

public function session()  
{  
    return $this->belongsTo(NewSession::class, 'new_session_id');  
}
}
