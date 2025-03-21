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
        Schema::create('coach_languages', function (Blueprint $table) {
            $table->unsignedBigInteger('coach_id');
            $table->foreign('coach_id')
            ->references('User_ID')

            ->on('coaches')
            ->onDelete('cascade');
            $table->enum('language', [
                'English', 'Spanish', 'French', 'German', 'Arabic', 
                'Chinese', 'Japanese', 'Russian', 'Portuguese', 'Other'
            ]);
            $table->primary(['coach_id', 'language']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coach_languages');
    }
};
