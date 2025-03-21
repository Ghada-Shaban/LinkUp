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
        Schema::create('chooses', function (Blueprint $table) {
        $table->unsignedBigInteger('coach_id');
        $table->unsignedBigInteger('service_id');
        $table->primary(['coach_id', 'service_id']);
        $table->foreign('coach_id')->references('User_ID')->on('coaches')->onDelete('cascade');
        $table->foreign('service_id')->references('service_id')->on('services')->onDelete('cascade');
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chooses');
    }
};
