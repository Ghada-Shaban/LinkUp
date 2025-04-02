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
        Schema::create('mentorship_requests', function (Blueprint $table) {
            $table->id();
            
            // Match the exact data type with the referenced tables
            $table->unsignedBigInteger('trainee_id');
            $table->unsignedBigInteger('coach_id');
            $table->unsignedBigInteger('service_id');
            
            $table->string('title');
            $table->enum('type', ['One_to_One', 'Group', 'Plan']);
            $table->enum('status', ['pending', 'accepted', 'rejected', 'completed'])->default('pending');
            $table->dateTime('first_session_time');
            $table->integer('duration_minutes');
            $table->json('plan_schedule')->nullable();
            $table->unsignedBigInteger('group_mentorship_id')->nullable();
            $table->unsignedBigInteger('mentorship_plan_id')->nullable();
            $table->timestamps();
        
            // Add foreign key constraints separately with explicit references
            $table->foreign('trainee_id')
                  ->references('User_ID')
                  ->on('trainees')
                  ->onDelete('cascade');
                  
            $table->foreign('coach_id')
                  ->references('User_ID')
                  ->on('coaches')
                  ->onDelete('cascade');
                  
            $table->foreign('service_id')
                  ->references('service_id')
                  ->on('services')
                  ->onDelete('cascade');
                  
            $table->foreign('group_mentorship_id')
                  ->references('service_id')
                  ->on('group_mentorships')
                  ->onDelete('set null');
                  
            $table->foreign('mentorship_plan_id')
                  ->references('service_id')
                  ->on('mentorship_plans')
                  ->onDelete('set null');
        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mentorship_requests');
    }
};
