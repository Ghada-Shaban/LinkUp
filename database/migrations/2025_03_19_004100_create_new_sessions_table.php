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
        Schema::create('new_sessions', function (Blueprint $table) {
            $table->id('new_session_id');
            $table->dateTime('date_time');
            $table->integer('duration');
            $table->enum('status', ['Pending', 'Scheduled', 'Completed', 'Cancelled'])->default('Pending');
            $table->string('meeting_link')->nullable();
            $table->unsignedBigInteger('service_id');
            $table->unsignedBigInteger('mentorship_request_id')->nullable(); // العمود الجديد للربط
            $table->timestamps();

            // Foreign key constraints
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('new_sessions');
    }
};
