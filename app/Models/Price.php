<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Price extends Model
{
    use HasFactory;
    protected $table = 'prices';
    protected $primaryKey = "price_id";
    protected $fillable = [
        'service_id',
        'price',
    ];

    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id');
    }
}
