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
        Schema::create('mentorships', function (Blueprint $table) {
            $table->unsignedBigInteger('service_id')->primary();
            $table->enum('mentorship_type', ['Mentorship session', 'Mentorship plan']);
            $table->foreign('service_id')->references('service_id')->on('services')->onDelete('cascade');
             $table->enum('role', [
                'Business',
                'Lower-Tech',
                'High-Tech',
                'Global Aspiration'
            ]);
            $table->enum('career_phase', [
                'Career Starter',
                'Career Accelerator'
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mentorships');
    }
};
