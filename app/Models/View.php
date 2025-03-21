<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class View extends Model
{
    use HasFactory;
    protected $table = 'views';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['user_id', 'service_id'];

    public function user()
    {
        return $this->belongsTo(User::class,'User_ID');
    }

    public function service()
    {
        return $this->belongsTo(Service::class,'service_id');
    }
}
