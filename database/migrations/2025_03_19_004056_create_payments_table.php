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
        Schema::create('payments', function (Blueprint $table) {
            $table->id('payment_id');
            $table->unsignedBigInteger('mentorship_request_id')->nullable(); // جعلناه nullable
            $table->decimal('amount', 10, 2);
            $table->enum('payment_method', ['Credit_Card', 'Fawry', 'InstaPay']);
            $table->enum('payment_status', ['Pending', 'Completed', 'Failed', 'Refunded', 'Cancelled']);
            $table->dateTime('date_time');
            $table->timestamps();

            $table->foreign('mentorship_request_id')
                  ->references('id')
                  ->on('mentorship_requests')
                  ->onDelete('set null') // تغيير من cascade إلى set null
                  ->onUpdate('cascade');

            // $table->unsignedBigInteger('service_id')->after('mentorship_request_id')->nullable();
            // $table->foreign('service_id')
            //       ->references('service_id')
            //       ->on('services')
            //       ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
