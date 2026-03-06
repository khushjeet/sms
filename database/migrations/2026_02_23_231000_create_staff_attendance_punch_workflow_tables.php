<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_attendance_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->time('auto_punch_out_time')->default('00:00:00');
            $table->boolean('require_selfie')->default(true);
            $table->boolean('allow_manual_override')->default(true);
            $table->unsignedSmallInteger('grace_minutes')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['effective_from', 'effective_to'], 'sap_effective_window_idx');
        });

        Schema::create('staff_attendance_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->date('attendance_date');
            $table->foreignId('attendance_policy_id')->nullable()->constrained('staff_attendance_policies')->nullOnDelete();
            $table->timestamp('punch_in_at')->nullable();
            $table->timestamp('punch_out_at')->nullable();
            $table->string('punch_in_selfie_path', 2048)->nullable();
            $table->string('punch_out_selfie_path', 2048)->nullable();
            $table->enum('punch_in_source', ['selfie', 'manual', 'biometric', 'import', 'system'])->default('selfie');
            $table->enum('punch_out_source', ['selfie', 'manual', 'biometric', 'import', 'system'])->default('selfie');
            $table->boolean('is_auto_punch_out')->default(false);
            $table->timestamp('auto_punch_out_at')->nullable();
            $table->string('auto_punch_out_reason', 255)->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->enum('review_status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('marked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('override_reason')->nullable();
            $table->timestamps();

            $table->unique(['staff_id', 'attendance_date'], 'sas_staff_date_uk');
            $table->index(['attendance_date', 'review_status'], 'sas_date_review_idx');
            $table->index(['is_auto_punch_out', 'attendance_date'], 'sas_auto_date_idx');
            $table->index(['staff_id', 'punch_in_at'], 'sas_staff_punchin_idx');
        });

        Schema::create('staff_attendance_punch_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('staff_attendance_session_id');
            $table->foreign('staff_attendance_session_id', 'sape_session_fk')
                ->references('id')
                ->on('staff_attendance_sessions')
                ->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->enum('punch_type', ['in', 'out', 'auto_out']);
            $table->timestamp('punched_at');
            $table->string('selfie_path', 2048)->nullable();
            $table->char('selfie_sha256', 64)->nullable();
            $table->json('selfie_metadata')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('location_accuracy_meters')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('device_id', 191)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->enum('source', ['selfie', 'manual', 'biometric', 'import', 'system'])->default('selfie');
            $table->boolean('is_system_generated')->default(false);
            $table->foreignId('captured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['staff_id', 'punched_at'], 'sape_staff_punched_idx');
            $table->index(['staff_attendance_session_id', 'punch_type'], 'sape_session_type_idx');
            $table->index(['punch_type', 'is_system_generated'], 'sape_type_system_idx');
        });

        Schema::create('staff_attendance_approval_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('staff_attendance_session_id');
            $table->foreign('staff_attendance_session_id', 'saal_session_fk')
                ->references('id')
                ->on('staff_attendance_sessions')
                ->cascadeOnDelete();
            $table->enum('from_status', ['pending', 'approved', 'rejected'])->nullable();
            $table->enum('to_status', ['pending', 'approved', 'rejected']);
            $table->enum('action', ['submitted', 'approved', 'rejected', 'reopened']);
            $table->foreignId('acted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acted_at');
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['staff_attendance_session_id', 'acted_at'], 'saal_session_acted_idx');
            $table->index(['acted_by', 'to_status'], 'saal_actor_status_idx');
        });

        Schema::table('staff_attendance_records', function (Blueprint $table) {
            $table->unsignedBigInteger('staff_attendance_session_id')
                ->nullable()
                ->after('staff_id');
            $table->foreign('staff_attendance_session_id', 'sar_session_fk')
                ->references('id')
                ->on('staff_attendance_sessions')
                ->nullOnDelete();
            $table->enum('source', ['summary', 'session', 'manual', 'import', 'system'])
                ->default('summary')
                ->after('status');
            $table->enum('approval_status', ['pending', 'approved', 'rejected'])
                ->default('approved')
                ->after('source');
            $table->foreignId('approved_by')
                ->nullable()
                ->after('updated_by')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');

            $table->index(['approval_status', 'attendance_date'], 'sar_approval_date_idx');
            $table->index(['source', 'attendance_date'], 'sar_source_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('staff_attendance_records', function (Blueprint $table) {
            $table->dropForeign('sar_session_fk');
            $table->dropForeign(['approved_by']);
            $table->dropIndex('sar_approval_date_idx');
            $table->dropIndex('sar_source_date_idx');
            $table->dropColumn([
                'staff_attendance_session_id',
                'source',
                'approval_status',
                'approved_by',
                'approved_at',
            ]);
        });

        Schema::dropIfExists('staff_attendance_approval_logs');
        Schema::dropIfExists('staff_attendance_punch_events');
        Schema::dropIfExists('staff_attendance_sessions');
        Schema::dropIfExists('staff_attendance_policies');
    }
};
