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
        

     Schema::create('trainee_areas_of_interest', function (Blueprint $table) {
        $table->unsignedBigInteger('trainee_id');
        $table->foreign('trainee_id')
             ->references('User_ID')
             ->on('trainees')
             ->onDelete('cascade');
      $table->enum('Area_Of_Interest', [
        'Full Stack Development',
        'Frontend Development',
        'Backend Development',
        'Software Development',
        'Data Science',
        'Data Analysis',
        'Artificial Intelligence',
        'Cybersecurity',
        'Cloud Computing',
        'Digital Marketing',
        'Graphic Design',
        'Product Management',
        'Business Analytics',
        'Finance and Investment',
        'Engineering',
        'Healthcare and Medicine',
        'Psychology and Counseling',
        'Education and Training',
        'Entrepreneurship',
        'Project Management',
        'Other'
     ]);
     $table->primary(['trainee_id', 'Area_Of_Interest']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trainee_interests');
    }
};
