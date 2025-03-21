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
        Schema::create('email_performance_reports', function (Blueprint $table) {
            $table->id('email_feedback_id');
            $table->text('strengths');
            $table->text('areas_for_improvement');
            $table->enum('rating', ['1', '2', '3', '4', '5']);
            $table->text('development_plan')->nullable();
            $table->text('comments')->nullable();
            $table->enum('email_feedback_status', ['Pending', 'Sent', 'Failed', 'Delivered']);
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('trainee_id');
            $table->foreign('session_id')->references('new_session_id')->on('new_sessions')->onDelete('cascade');
            $table->foreign('trainee_id')->references('User_ID')->on('trainees')->onDelete('cascade');
            $table->timestamps();
        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_performance_reports');
    }
};
