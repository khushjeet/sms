<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_attendance_month_locks', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->boolean('is_locked')->default(true);
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('unlocked_at')->nullable();
            $table->foreignId('unlocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('override_reason')->nullable();
            $table->timestamps();

            $table->unique(['year', 'month']);
            $table->index(['is_locked', 'year', 'month']);
        });

        Schema::create('staff_attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->date('attendance_date');
            $table->enum('status', ['present', 'absent', 'half_day', 'leave']);
            $table->unsignedSmallInteger('late_minutes')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('override_reason')->nullable();
            $table->timestamps();

            $table->unique(['staff_id', 'attendance_date']);
            $table->index(['attendance_date', 'status']);
        });

        Schema::create('leave_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->nullable()->constrained('leave_types')->nullOnDelete();
            $table->enum('entry_type', ['credit', 'debit', 'adjustment']);
            $table->decimal('quantity', 8, 2);
            $table->date('entry_date');
            $table->string('reference_type', 100)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['staff_id', 'entry_date']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('salary_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('salary_template_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_template_id')->constrained('salary_templates')->cascadeOnDelete();
            $table->string('component_name');
            $table->enum('component_type', ['earning', 'deduction']);
            $table->decimal('amount', 12, 2)->nullable();
            $table->decimal('percentage', 6, 2)->nullable();
            $table->boolean('is_taxable')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['salary_template_id', 'sort_order']);
        });

        Schema::create('staff_salary_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('salary_template_id')->constrained('salary_templates')->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['staff_id', 'effective_from']);
            $table->index(['staff_id', 'status', 'effective_from']);
        });

        Schema::create('payroll_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('period_start');
            $table->date('period_end');
            $table->enum('status', ['generated', 'finalized', 'paid'])->default('generated');
            $table->boolean('is_locked')->default(false);
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->unique(['year', 'month']);
            $table->index(['status', 'year', 'month']);
        });

        Schema::create('payroll_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_batch_id')->constrained('payroll_batches')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();
            $table->foreignId('staff_salary_structure_id')->nullable()->constrained('staff_salary_structures')->nullOnDelete();
            $table->unsignedTinyInteger('days_in_month');
            $table->decimal('payable_days', 6, 2)->default(0);
            $table->decimal('leave_days', 6, 2)->default(0);
            $table->decimal('absent_days', 6, 2)->default(0);
            $table->decimal('gross_pay', 12, 2)->default(0);
            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('net_pay', 12, 2)->default(0);
            $table->json('snapshot');
            $table->timestamps();

            $table->unique(['payroll_batch_id', 'staff_id']);
            $table->index(['staff_id', 'payroll_batch_id']);
        });

        Schema::create('payroll_item_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_batch_item_id')->constrained('payroll_batch_items')->cascadeOnDelete();
            $table->enum('adjustment_type', ['recovery', 'bonus', 'correction']);
            $table->decimal('amount', 12, 2);
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['payroll_batch_item_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_item_adjustments');
        Schema::dropIfExists('payroll_batch_items');
        Schema::dropIfExists('payroll_batches');
        Schema::dropIfExists('staff_salary_structures');
        Schema::dropIfExists('salary_template_components');
        Schema::dropIfExists('salary_templates');
        Schema::dropIfExists('leave_ledger_entries');
        Schema::dropIfExists('staff_attendance_records');
        Schema::dropIfExists('staff_attendance_month_locks');
    }
};
