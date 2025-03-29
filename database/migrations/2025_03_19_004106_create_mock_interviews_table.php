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
        Schema::create('mock_interviews', function (Blueprint $table) {
            $table->unsignedBigInteger('service_id')->primary();
            $table->enum('interview_type', ['Technical Interview', ' Soft Skills', 'Comprehensive Preparation']);
            $table->enum('interview_level', ['Junior', ' Mid-Level', 'Senior', ' Premium (FAANG)']);
            $table->foreign('service_id')->references('service_id')->on('services')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mock_interviews');
    }
};
