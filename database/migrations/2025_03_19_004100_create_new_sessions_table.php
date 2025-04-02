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
        Schema::create('new_sessions', function (Blueprint $table) {
            $table->id('new_session_id');
            $table->dateTime('date_time');
            $table->integer('duration');
            $table->enum('status', ['Scheduled', 'Completed', 'Cancelled']);
            $table->string('meeting_link')->nullable(); // تعديل هنا بإزالة after()
            $table->unsignedBigInteger('service_id');
            $table->foreign('service_id')->references('service_id')->on('services')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('new_sessions'); // تصحيح الخطأ هنا
    }
};
