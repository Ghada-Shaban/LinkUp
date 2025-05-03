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
            $table->unsignedBigInteger('service_id')->nullable(); // تعريف العمود من غير after
            $table->decimal('amount', 10, 2);
            $table->enum('payment_method', ['Credit_Card', 'Fawry', 'InstaPay']);
            $table->enum('payment_status', ['Pending', 'Completed', 'Failed', 'Refunded', 'Cancelled']);
            $table->dateTime('date_time');
            $table->timestamps();

            // تحديد مكان service_id باستخدام after بعد تعريف العمود
            $table->after('mentorship_request_id', function (Blueprint $table) {
                // هنا بنعتمد على أن العمود service_id تم تعريفه بالفعل
            });

            // تعريف الـ foreign keys
            $table->foreign('mentorship_request_id')
                  ->references('id')
                  ->on('mentorship_requests')
                  ->onDelete('set null')
                  ->onUpdate('cascade');

            $table->foreign('service_id')
                  ->references('service_id')
                  ->on('services')
                  ->onDelete('set null');
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
