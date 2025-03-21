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
     

      Schema::create('trainee_preferred_languages', function (Blueprint $table) {
        $table->unsignedBigInteger('trainee_id');
        $table->foreign('trainee_id')
             ->references('User_ID')
             ->on('trainees')
             ->onDelete('cascade');
      
      $table->enum('Language', [
        'English',
        'Spanish',
        'French',
        'German',
        'Arabic',
        'Chinese',
        'Japanese',
        'Russian',
        'Portuguese',
        'Other'
      ]);
      $table->primary(['trainee_id', 'Language']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trainee_languages');
    }
};
