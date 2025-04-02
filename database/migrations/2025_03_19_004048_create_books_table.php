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
        Schema::create('books', function (Blueprint $table) {
            $table->unsignedBigInteger('trainee_id');
            $table->unsignedBigInteger('session_id');
            $table->primary(['trainee_id', 'session_id']);
            $table->foreign('trainee_id')->references('User_ID')->on('trainees')->onDelete('cascade');
            $table->foreign('session_id')->references('new_session_id')->on('new_sessions')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};
