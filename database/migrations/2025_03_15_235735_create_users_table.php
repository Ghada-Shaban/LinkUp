<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id('User_ID');
            $table->string('full_name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('linkedin_link')->nullable();
            $table->enum('role_profile', ['Coach', 'Trainee']);
            $table->string('photo')->nullable();
            $table->timestamps();
            $table->string('Photo_Public_ID')->nullable();
           
        });
    }

   
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
