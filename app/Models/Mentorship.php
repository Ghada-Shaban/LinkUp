<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Mentorship extends Model
{
    use HasFactory;
    protected $table = 'mentorships';
    public $timestamps = false;
    protected $fillable = [
        'service_id',
        'mentorship_type'
    ];
    public function service()  
{  
    return $this->belongsTo(Service::class, 'service_id');  
}
}
