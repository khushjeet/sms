<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compiled_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->date('marked_on');
            $table->decimal('marks_obtained', 6, 2)->nullable();
            $table->decimal('max_marks', 6, 2)->default(100);
            $table->text('remarks')->nullable();
            $table->boolean('is_finalized')->default(false);
            $table->foreignId('compiled_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('compiled_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['enrollment_id', 'subject_id', 'section_id', 'academic_year_id', 'marked_on'],
                'compiled_marks_unique_sheet_row'
            );
            $table->index(['section_id', 'subject_id', 'academic_year_id', 'marked_on'], 'compiled_marks_sheet_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compiled_marks');
    }
};
