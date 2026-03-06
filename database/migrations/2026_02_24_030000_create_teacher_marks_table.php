<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->restrictOnDelete();
            $table->date('marked_on');
            $table->decimal('marks_obtained', 6, 2)->nullable();
            $table->decimal('max_marks', 6, 2)->default(100);
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['enrollment_id', 'subject_id', 'academic_year_id', 'marked_on'], 'teacher_marks_unique_sheet_row');
            $table->index(['teacher_id', 'subject_id', 'section_id', 'academic_year_id'], 'teacher_marks_teacher_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_marks');
    }
};

