<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Choose extends Model
{
    use HasFactory;
    protected $table = 'chooses';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['coach_id', 'service_id'];

    public function coach()
    {
        return $this->belongsTo(Coach::class, 'coach_id', 'User_ID');
    }

    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id');
    }
}
