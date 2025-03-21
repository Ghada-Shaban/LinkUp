<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AddedTo extends Model
{
    use HasFactory;
    
    protected $table = 'added_to';

    protected $fillable = [
        'cart_id',
        'new_session_id'
    ];

    protected $primaryKey = ['cart_id', 'new_session_id'];
    public $incrementing = false;
    
    public function cart()
    {
        return $this->belongsTo(Cart::class, 'cart_id');
    }

    public function session()
    {
        return $this->belongsTo(NewSession::class, 'new_session_id');
    }
}
