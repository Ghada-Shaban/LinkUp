<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('group_mentorship_slots', function (Blueprint $table) {
            $table->id('slot_id');
            $table->unsignedBigInteger('service_id');
            $table->foreign('service_id')->references('service_id')->on('group_mentorships')->onDelete('cascade');
            $table->dateTime('start_time');
            $table->integer('duration');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('group_mentorship_slots');
    }
};
