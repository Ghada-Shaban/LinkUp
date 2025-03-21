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
        Schema::create('coach_skills', function (Blueprint $table) {
            $table->unsignedBigInteger('coach_id'); 
            $table->foreign('coach_id')
             ->references('User_ID')
             ->on('coaches')
             ->onDelete('cascade');
            $table->enum('skill', [
                'Leadership', 'Career Development', 'Communication', 'Time Management',
                'Personal Development', 'Executive Coaching', 'Team Building', 'Conflict Resolution',
                'Emotional Intelligence', 'Wellness Coaching', 'Technical Skills', 'Entrepreneurship',
                'Diversity Equity Inclusion', 'Public Speaking', 'Other'
            ]);
            $table->primary(['coach_id', 'skill']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coach_skills');
    }
};
