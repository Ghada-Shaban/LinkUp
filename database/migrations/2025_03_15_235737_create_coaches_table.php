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
        Schema::create('coaches', function (Blueprint $table) {
            
            $table->unsignedBigInteger('User_ID');
            $table->foreign('User_ID')
              ->references('User_ID')
              ->on('users')
             ->onDelete('cascade');
            

            $table->string('Title');
            $table->string('Company_or_School');
            $table->text('Bio');
            $table->foreignId('admin_id')->constrained('admins')->onDelete('cascade');
            $table->integer('Years_Of_Experience')->default(0);
            $table->integer('Months_Of_Experience')->default(0);
            $table->timestamps();
            $table->primary('User_ID');
            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coaches');
    }
};
