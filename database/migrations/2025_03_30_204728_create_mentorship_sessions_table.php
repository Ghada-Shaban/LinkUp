<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mentorship_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('service_id')->primary();
            $table->enum('session_type', ['CV Review', 'project Assessment', 'Linkedin Optimization']);
            $table->foreign('service_id')->references('service_id')->on('mentorships')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mentorship_sessions');
    }
};