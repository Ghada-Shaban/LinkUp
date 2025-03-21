<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    use HasFactory;
    protected $table = 'carts';

    protected $fillable = [
        'price',
        'quantity',
        'status',
        'payment_id'
    ];

    public function payment()  
{  
    return $this->belongsTo(Payment::class, 'payment_id');  
}  

public function sessions()  
{  
    return $this->belongsToMany(  
        NewSession::class,  
        'added_to',  
        'cart_id',  
        'new_session_id'  
    );  
}
}
