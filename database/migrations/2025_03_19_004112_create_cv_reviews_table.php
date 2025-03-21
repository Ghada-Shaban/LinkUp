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
        Schema::create('cv_reviews', function (Blueprint $table) {
            $table->unsignedBigInteger('service_id')->primary();
            $table->enum('file_format', ['PDF', 'DOCX', 'Other']);
            $table->string('file_path', 255);
            $table->double('file_size');
            $table->enum('review_status', ['Pending', 'In_Review', 'Completed']);
            $table->date('submission_date');
            $table->foreign('service_id')->references('service_id')->on('services')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cv_reviews');
    }
};
