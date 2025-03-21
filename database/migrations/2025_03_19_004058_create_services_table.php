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
        Schema::create('services', function (Blueprint $table) {
            $table->id('service_id');
            $table->decimal('cost', 10, 2);
            $table->text('service_description');
            $table->enum('service_type', ['Linkedin_Optimization', 'Mock_Interview', 'Mentorship', 'Project_Evaluation', 'CV_Review']);
            $table->foreignId('admin_id')->constrained('admins')->onDelete('cascade'); 
            $table->timestamps();
        });
        
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
