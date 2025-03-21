<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReceivedBy extends Model
{
    use HasFactory;
    protected $table = 'received_by';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['user_id', 'email_notification_id'];

    public function user()
    {
        return $this->belongsTo(User::class,'User_ID');
    }

    public function emailNotification()
    {
        return $this->belongsTo(EmailNotification::class, 'email_notification_id');
    }
}
