<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->enum('type', ['core', 'elective', 'optional'])->default('core');
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        // Subjects assigned to class for specific academic year
        Schema::create('class_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained()->onDelete('cascade');
            $table->foreignId('subject_id')->constrained()->onDelete('cascade');
            $table->foreignId('academic_year_id')->constrained()->onDelete('cascade');
            $table->integer('max_marks')->default(100);
            $table->integer('pass_marks')->default(35);
            $table->boolean('is_mandatory')->default(true);
            $table->timestamps();
            
            $table->unique(['class_id', 'subject_id', 'academic_year_id'], 'class_subject_year_unique');
        });

        // Teacher assigned to teach subject in specific section
        Schema::create('teacher_subject_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('section_id')->constrained()->onDelete('cascade');
            $table->foreignId('subject_id')->constrained()->onDelete('cascade');
            $table->foreignId('academic_year_id')->constrained()->onDelete('cascade');
            $table->timestamps();
            
            $table->unique(['teacher_id', 'section_id', 'subject_id', 'academic_year_id'], 'teacher_section_subject_year_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_subject_assignments');
        Schema::dropIfExists('class_subjects');
        Schema::dropIfExists('subjects');
    }
};
