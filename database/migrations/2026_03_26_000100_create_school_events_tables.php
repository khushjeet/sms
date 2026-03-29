<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            $table->string('title');
            $table->date('event_date')->nullable();
            $table->string('venue')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('certificate_prefix', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('school_event_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_event_id')->constrained('school_events')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('enrollments')->nullOnDelete();
            $table->unsignedTinyInteger('rank')->nullable();
            $table->string('achievement_title')->nullable();
            $table->string('remarks')->nullable();
            $table->timestamps();

            $table->unique(['school_event_id', 'student_id'], 'school_event_participants_unique_student');
            $table->unique(['school_event_id', 'rank'], 'school_event_participants_unique_rank');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_event_participants');
        Schema::dropIfExists('school_events');
    }
};
