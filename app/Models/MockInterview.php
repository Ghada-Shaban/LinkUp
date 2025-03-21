<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MockInterview extends Model
{
    use HasFactory;
    
    protected $table = 'mock_interviews';
    public $timestamps = false;
    protected $fillable = [
        'service_id',
        'interview_type'
    ];
    public function service()  
{  
    return $this->belongsTo(Service::class, 'service_id');  
}
}
