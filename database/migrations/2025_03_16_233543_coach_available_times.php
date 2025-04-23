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
        Schema::create('coach_available_times', function (Blueprint $table) {
            // Foreign key referencing the `coaches` table
            $table->unsignedBigInteger('coach_id');

            // Day of the week (enum)
            $table->enum('Day_Of_Week', [
                'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'
            ]);

            // Start and end time for the availability slot
            $table->time('Start_Time');
            $table->time('End_Time');

            // Composite primary key to ensure uniqueness
            $table->primary(['coach_id', 'Day_Of_Week', 'Start_Time', 'End_Time']);

            // Timestamps for created_at and updated_at
            $table->timestamps();
            $table->foreign('coach_id')
                  ->references('User_ID')
                  ->on('coaches') // or 'users' depending on your table
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coach_available_times');
    }
};
