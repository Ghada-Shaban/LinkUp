<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MockInterview extends Model
{
    use HasFactory;
    
    protected $table = 'mock_interviews';
       

    protected $primaryKey = "service_id";

    public $timestamps = false;
    protected $fillable = [
        'service_id',
        'interview_type','interview_level'
    ];
    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id', 'service_id');
    }
}
