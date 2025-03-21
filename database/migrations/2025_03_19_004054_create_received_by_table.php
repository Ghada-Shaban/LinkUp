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
        Schema::create('received_by', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('email_notification_id');
            $table->primary(['user_id', 'email_notification_id']);

            $table->foreign('user_id')->references('User_ID')->on('users')->onDelete('cascade');
            $table->foreign('email_notification_id')->references('email_notification_id')->on('email_notifications')->onDelete('cascade');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('received_by');
    }
};
