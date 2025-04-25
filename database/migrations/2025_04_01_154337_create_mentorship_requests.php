<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
      Schema::create('mentorship_requests', function (Blueprint $table) {
            $table->id();
            
            // Polymorphic relationship to service types
            $table->unsignedBigInteger('requestable_id');
            $table->string('requestable_type'); // 'App\Models\MentorshipPlan' or 'App\Models\GroupMentorship'
            
            // Participants
            $table->unsignedBigInteger('trainee_id');
            $table->unsignedBigInteger('coach_id');
            
            // Status tracking
            $table->enum('status', ['pending', 'accepted', 'rejected'])->default('pending');
            $table->timestamp('responded_at')->nullable();
            
            // Timestamps
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('trainee_id')->references('User_ID')->on('trainees')->onDelete('cascade');
            $table->foreign('coach_id')->references('User_ID')->on('coaches')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mentorship_requests');
    }
};
