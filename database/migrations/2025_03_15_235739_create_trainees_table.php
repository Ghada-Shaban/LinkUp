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
        Schema::create('trainees', function (Blueprint $table) {
            
            $table->unsignedBigInteger('User_ID');
         $table->foreign('User_ID')
          ->references('User_ID')
          ->on('users')
          ->onDelete('cascade');
            $table->enum('Education_Level', [
                'Associate Degree',
                'Bachelor’s Degree',
                'Master’s Degree',
                'PhD',
                'Other'
            ]);
            $table->string('Institution_Or_School');
            $table->string('Field_Of_Study');
            $table->string('Current_Role')->nullable();
            $table->text('Story')->nullable();
            $table->integer('Years_Of_Professional_Experience')->nullable();
            $table->timestamps();
            $table->primary('User_ID');

            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trainees');
    }
};
