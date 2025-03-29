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
        Schema::create('group_mentorships', function (Blueprint $table) {
            $table->unsignedBigInteger('service_id')->primary();
            $table->string('title');
            $table->text('description');
            $table->integer('min_participants')->default(2);
            $table->integer('max_participants')->default(5);
            $table->foreign('service_id')->references('service_id')->on('services')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('group_mentorships');
    }
};
