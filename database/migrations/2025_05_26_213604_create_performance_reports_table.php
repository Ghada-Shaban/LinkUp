<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('coach_id');
            $table->unsignedBigInteger('trainee_id');
            $table->integer('overall_rating')->check('overall_rating BETWEEN 1 AND 5');
            $table->text('strengths');
            $table->text('weaknesses');
            $table->text('comments')->nullable(); 
            $table->timestamps();

            $table->foreign('session_id')->references('new_session_id')->on('new_sessions')->onDelete('cascade');
            $table->foreign('coach_id')->references('User_ID')->on('users')->onDelete('cascade');
            $table->foreign('trainee_id')->references('User_ID')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_reports');
    }
};