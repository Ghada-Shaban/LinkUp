<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailNotification extends Model
{
    use HasFactory;
    
    protected $table = 'email_notifications';
    public $timestamps = false; 
    protected $fillable = [
        'recipient_email',
        'sender_email',
        'notification_type',
        'email_notification_status',
        'subject',
        'date_time_sent',
        'body'
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'received_by', 'email_notification_id', 'User_ID');
    }

    
    protected $casts = [
        'date_time_sent' => 'datetime'
    ];

   
}
