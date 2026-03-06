<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admit_schedule_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_session_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('snapshot_version')->default(1);
            $table->json('schedule_snapshot');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['exam_session_id', 'snapshot_version'], 'admit_schedule_snapshots_unique_version');
            $table->index(['exam_session_id', 'created_at'], 'admit_schedule_snapshots_session_created_idx');
        });

        Schema::create('admit_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('admit_schedule_snapshot_id')->constrained('admit_schedule_snapshots')->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->string('roll_number', 50)->nullable();
            $table->string('seat_number', 50)->nullable();
            $table->string('center_name', 150)->nullable();
            $table->enum('status', ['draft', 'published', 'blocked', 'revoked'])->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_superseded')->default(false);
            $table->text('remarks')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->uuid('verification_uuid')->unique();
            $table->char('verification_hash', 64);
            $table->enum('verification_status', ['active', 'revoked'])->default('active');
            $table->timestamps();

            $table->unique(['exam_session_id', 'enrollment_id', 'version'], 'admit_cards_unique_version');
            $table->index(['exam_session_id', 'is_superseded', 'status'], 'admit_cards_session_status_idx');
            $table->index(['student_id', 'is_superseded'], 'admit_cards_student_active_idx');
        });

        Schema::create('admit_visibility_controls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admit_card_id')->constrained('admit_cards')->restrictOnDelete();
            $table->enum('visibility_status', ['visible', 'withheld', 'under_review', 'disciplinary_hold'])->default('visible');
            $table->text('blocked_reason')->nullable();
            $table->foreignId('blocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('blocked_at')->nullable();
            $table->foreignId('unblocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('unblocked_at')->nullable();
            $table->unsignedInteger('visibility_version')->default(1);
            $table->timestamps();

            $table->unique(['admit_card_id', 'visibility_version'], 'admit_visibility_controls_unique_version');
            $table->index(['admit_card_id', 'visibility_status'], 'admit_visibility_controls_status_idx');
        });

        Schema::create('admit_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('exam_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('admit_card_id')->nullable()->constrained('admit_cards')->nullOnDelete();
            $table->enum('action', ['generate', 'regenerate', 'publish', 'block', 'unblock', 'revoke']);
            $table->unsignedInteger('old_version')->nullable();
            $table->unsignedInteger('new_version')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['exam_session_id', 'action'], 'admit_audit_logs_session_action_idx');
            $table->index(['admit_card_id', 'action'], 'admit_audit_logs_card_action_idx');
        });

        Schema::create('admit_verification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admit_card_id')->nullable()->constrained('admit_cards')->nullOnDelete();
            $table->uuid('verification_uuid')->nullable();
            $table->enum('status', ['verified', 'invalid', 'missing', 'revoked', 'superseded', 'withheld']);
            $table->string('message', 200);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('verified_at')->nullable();

            $table->index(['verification_uuid', 'status'], 'admit_verification_logs_uuid_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admit_verification_logs');
        Schema::dropIfExists('admit_audit_logs');
        Schema::dropIfExists('admit_visibility_controls');
        Schema::dropIfExists('admit_cards');
        Schema::dropIfExists('admit_schedule_snapshots');
    }
};
