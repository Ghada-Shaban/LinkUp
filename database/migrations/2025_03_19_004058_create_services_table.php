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
        Schema::create('services', function (Blueprint $table) {
            $table->id('service_id');
            $table->enum('service_type', ['Mentorship', 'Mock_Interview', 'Group_Mentorship']);
            $table->foreignId('admin_id')->constrained('admins')->onDelete('cascade');
            $table->unsignedBigInteger('coach_id'); 
            $table->foreign('coach_id')->references('User_ID')->on('coaches')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
