<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LinkedinOptimization extends Model
{
    use HasFactory;
    protected $table = 'linkedin_optimizations';
    public $timestamps = false;
    protected $fillable = [
        'service_id',
        'profile_link'
    ];
    public function service()  
{  
    return $this->belongsTo(Service::class, 'service_id');  
}
}
