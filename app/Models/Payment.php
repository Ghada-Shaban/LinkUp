<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;
    protected $table = 'payments';

    protected $fillable = [
        'amount',
        'payment_method',
        'payment_status',
        'date_time'
    ];
    public function cart()
    {
        return $this->hasOne(Cart::class, 'payment_id'); 
    }
}
