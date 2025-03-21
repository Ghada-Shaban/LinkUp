<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('email_notifications', function (Blueprint $table) {
            $table->id('email_notification_id');
            $table->string('recipient_email', 100);
            $table->string('sender_email', 100);
            $table->enum('notification_type', ['Reminder', 'Alert', 'Promotion', 'Acceptation', 'Rejection', 'Other']);
            $table->enum('email_notification_status', ['Pending', 'Sent', 'Failed', 'Delivered']);
            $table->string('subject', 100);
            $table->dateTime('date_time_sent');
            $table->text('body');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_notifications');
    }
};
