<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mentorship_request_id');
            $table->timestamp('payment_due_at');
            $table->timestamps();
            
            $table->foreign('mentorship_request_id')->references('id')->on('mentorship_requests')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_payments');
    }
};