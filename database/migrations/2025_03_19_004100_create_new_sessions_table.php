<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('new_sessions', function (Blueprint $table) {
            $table->id('new_session_id');
            $table->unsignedBigInteger('coach_id'); // شيلنا after('id')
            $table->unsignedBigInteger('trainee_id'); // شيلنا after('coach_id')
            $table->dateTime('date_time')->index();
            $table->integer('duration');
            $table->enum('status', ['Pending', 'Scheduled', 'Completed', 'Cancelled'])->default('Pending')->index();
            $table->enum('payment_status', ['Pending', 'Completed', 'Failed'])->default('Pending');
            $table->string('meeting_link')->nullable();
            $table->unsignedBigInteger('service_id');
            $table->unsignedBigInteger('mentorship_request_id')->nullable(); // شيلنا after('service_id')
            $table->timestamps();

            // إضافة المفاتيح الأجنبية (Foreign Keys)
            $table->foreign('coach_id')
                  ->references('User_ID')
                  ->on('users')
                  ->onDelete('cascade');

            $table->foreign('trainee_id')
                  ->references('User_ID')
                  ->on('users')
                  ->onDelete('cascade');

            $table->foreign('service_id')
                  ->references('service_id')
                  ->on('services')
                  ->onDelete('cascade');

            $table->foreign('mentorship_request_id')
                  ->references('id')
                  ->on('mentorship_requests')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('new_sessions');
    }
};
