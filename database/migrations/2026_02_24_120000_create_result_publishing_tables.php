<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            $table->string('name', 150);
            $table->enum('status', ['draft', 'compiling', 'published', 'locked'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['academic_year_id', 'class_id', 'name'], 'exam_sessions_unique_scope_name');
            $table->index(['status', 'academic_year_id'], 'exam_sessions_status_year_idx');
        });

        Schema::create('grading_schemes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('board_id')->nullable();
            $table->decimal('min_percentage', 5, 2);
            $table->decimal('max_percentage', 5, 2);
            $table->string('grade', 10);
            $table->decimal('grade_point', 4, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'min_percentage', 'max_percentage'], 'grading_schemes_active_band_idx');
        });

        Schema::create('student_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->decimal('total_marks', 8, 2);
            $table->decimal('total_max_marks', 8, 2);
            $table->decimal('percentage', 6, 2);
            $table->string('grade', 10)->nullable();
            $table->unsignedInteger('rank')->nullable();
            $table->enum('result_status', ['pass', 'fail', 'compartment'])->default('pass');
            $table->text('remarks')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_superseded')->default(false);
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->uuid('verification_uuid')->unique();
            $table->char('verification_hash', 64);
            $table->enum('verification_status', ['active', 'revoked'])->default('active');
            $table->timestamps();

            $table->unique(['exam_session_id', 'enrollment_id', 'version'], 'student_results_unique_version');
            $table->index(['exam_session_id', 'student_id', 'version'], 'student_results_lookup_idx');
            $table->index(['exam_session_id', 'is_superseded'], 'student_results_session_active_idx');
        });

        Schema::create('result_marks_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_result_id')->constrained('student_results')->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->decimal('obtained_marks', 8, 2);
            $table->decimal('max_marks', 8, 2);
            $table->string('grade', 10)->nullable();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('snapshot_version')->default(1);
            $table->timestamp('created_at')->nullable();

            $table->index(['exam_session_id', 'student_id', 'snapshot_version'], 'result_marks_snapshots_student_idx');
            $table->index(['exam_session_id', 'subject_id'], 'result_marks_snapshots_subject_idx');
        });

        Schema::create('result_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_result_id')->constrained('student_results')->restrictOnDelete();
            $table->string('file_path');
            $table->char('checksum', 64);
            $table->string('checksum_algorithm', 20)->default('sha256');
            $table->timestamp('generated_at');
            $table->timestamps();
        });

        Schema::create('result_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('student_result_id')->nullable()->constrained('student_results')->nullOnDelete();
            $table->enum('action', ['publish', 'revise', 'lock', 'unlock', 'revoke_verification']);
            $table->unsignedInteger('old_version')->nullable();
            $table->unsignedInteger('new_version')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['student_result_id', 'action'], 'result_audit_logs_result_action_idx');
        });

        Schema::create('result_visibility_controls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_result_id')->constrained('student_results')->restrictOnDelete();
            $table->enum('visibility_status', ['visible', 'withheld', 'under_review', 'disciplinary_hold'])->default('visible');
            $table->text('blocked_reason')->nullable();
            $table->foreignId('blocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('blocked_at')->nullable();
            $table->foreignId('unblocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('unblocked_at')->nullable();
            $table->unsignedInteger('visibility_version')->default(1);
            $table->timestamps();

            $table->unique(['student_result_id', 'visibility_version'], 'result_visibility_controls_unique_version');
            $table->index(['student_result_id', 'visibility_status'], 'result_visibility_controls_status_idx');
        });

        Schema::create('visibility_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('student_result_id')->nullable()->constrained('student_results')->nullOnDelete();
            $table->enum('action', ['blocked', 'unblocked', 'updated']);
            $table->text('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('result_verification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_result_id')->nullable()->constrained('student_results')->nullOnDelete();
            $table->uuid('verification_uuid')->nullable();
            $table->enum('status', ['verified', 'invalid', 'missing', 'revoked', 'superseded']);
            $table->string('message', 200);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('verified_at')->nullable();

            $table->index(['verification_uuid', 'status'], 'result_verification_logs_uuid_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('result_verification_logs');
        Schema::dropIfExists('visibility_audit_logs');
        Schema::dropIfExists('result_visibility_controls');
        Schema::dropIfExists('result_audit_logs');
        Schema::dropIfExists('result_documents');
        Schema::dropIfExists('result_marks_snapshots');
        Schema::dropIfExists('student_results');
        Schema::dropIfExists('grading_schemes');
        Schema::dropIfExists('exam_sessions');
    }
};

