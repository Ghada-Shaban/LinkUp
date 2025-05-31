<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   
     public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id(); 
            $table->unsignedBigInteger('trainee_id');
            $table->unsignedBigInteger('coach_id');
            $table->enum('rating', ['1', '2', '3', '4', '5']);
            $table->text('comment')->nullable();
            $table->timestamps();

          
            $table->foreign('trainee_id')->references('User_ID')->on('trainees')->onDelete('cascade');
            $table->foreign('coach_id')->references('User_ID')->on('coaches')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
