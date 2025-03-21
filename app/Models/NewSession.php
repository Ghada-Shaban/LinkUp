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
        'service_id'
    ];
    public function service()
    {
        return $this->belongsTo(Service::class,'service_id');

    }

    public function trainees()  
{  
    return $this->belongsToMany(  
        Trainee::class,  
        'books',  
        'new_session_id',  
        'trainee_id' 
    ); 
     
}
}