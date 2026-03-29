<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->hardenTeacherMarksForeignKeys();
        $this->hardenCompiledMarksForeignKeys();

        Schema::create('compiled_mark_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compiled_mark_id')->constrained('compiled_marks')->restrictOnDelete();
            $table->unsignedInteger('version_no');
            $table->string('action', 20);
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('exam_configuration_id')->nullable()->constrained('academic_year_exam_configs')->nullOnDelete();
            $table->foreignId('exam_session_id')->nullable()->constrained('exam_sessions')->nullOnDelete();
            $table->date('marked_on');
            $table->decimal('marks_obtained', 6, 2)->nullable();
            $table->decimal('max_marks', 6, 2)->nullable();
            $table->text('remarks')->nullable();
            $table->boolean('is_finalized')->default(false);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at')->nullable();
            $table->json('metadata')->nullable();

            $table->unique(['compiled_mark_id', 'version_no'], 'compiled_mark_histories_unique_version');
            $table->index(['enrollment_id', 'subject_id', 'marked_on'], 'compiled_mark_histories_lookup_idx');
        });

        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->string('class_name_snapshot', 150)->nullable()->after('name');
            $table->string('exam_name_snapshot', 150)->nullable()->after('class_name_snapshot');
            $table->string('academic_year_label_snapshot', 150)->nullable()->after('exam_name_snapshot');
            $table->json('school_snapshot')->nullable()->after('academic_year_label_snapshot');
            $table->timestamp('identity_locked_at')->nullable()->after('school_snapshot');
        });

        Schema::table('result_marks_snapshots', function (Blueprint $table) {
            $table->decimal('passing_marks', 8, 2)->nullable()->after('max_marks');
            $table->string('subject_name_snapshot', 255)->nullable()->after('passing_marks');
            $table->string('subject_code_snapshot', 100)->nullable()->after('subject_name_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('result_marks_snapshots', function (Blueprint $table) {
            $table->dropColumn(['passing_marks', 'subject_name_snapshot', 'subject_code_snapshot']);
        });

        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'class_name_snapshot',
                'exam_name_snapshot',
                'academic_year_label_snapshot',
                'school_snapshot',
                'identity_locked_at',
            ]);
        });

        Schema::dropIfExists('compiled_mark_histories');
    }

    private function hardenTeacherMarksForeignKeys(): void
    {
        Schema::table('teacher_marks', function (Blueprint $table) {
            $table->dropForeign(['enrollment_id']);
        });

        Schema::table('teacher_marks', function (Blueprint $table) {
            $table->foreign('enrollment_id')->references('id')->on('enrollments')->restrictOnDelete();
        });
    }

    private function hardenCompiledMarksForeignKeys(): void
    {
        Schema::table('compiled_marks', function (Blueprint $table) {
            $table->dropForeign(['enrollment_id']);
            $table->dropForeign(['subject_id']);
            $table->dropForeign(['section_id']);
            $table->dropForeign(['academic_year_id']);
            $table->dropForeign(['compiled_by']);
        });

        Schema::table('compiled_marks', function (Blueprint $table) {
            $table->foreign('enrollment_id')->references('id')->on('enrollments')->restrictOnDelete();
            $table->foreign('subject_id')->references('id')->on('subjects')->restrictOnDelete();
            $table->foreign('section_id')->references('id')->on('sections')->restrictOnDelete();
            $table->foreign('academic_year_id')->references('id')->on('academic_years')->restrictOnDelete();
            $table->foreign('compiled_by')->references('id')->on('users')->restrictOnDelete();
        });
    }
};
