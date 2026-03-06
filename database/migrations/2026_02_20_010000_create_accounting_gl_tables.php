<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_years', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // e.g. FY2025-26
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_closed')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['start_date', 'end_date']);
        });

        Schema::create('financial_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_year_id')->constrained('financial_years');
            $table->unsignedTinyInteger('month'); // 1-12 (financial year month index)
            $table->string('label'); // e.g. Apr 2025
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_locked')->default(false);
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['financial_year_id', 'month']);
            $table->index(['financial_year_id', 'start_date', 'end_date']);
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('accounts');
            $table->string('code')->unique(); // stable key for long-term references
            $table->string('name');
            $table->string('type'); // asset|liability|equity|income|expense
            $table->boolean('is_cash')->default(false);
            $table->boolean('is_bank')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_year_id')->constrained('financial_years');
            $table->foreignId('financial_period_id')->constrained('financial_periods');
            $table->date('entry_date');
            $table->timestamp('posted_at');
            $table->string('source_type')->nullable(); // payment|receipt|transport_cycle|special_fee|manual
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('narration')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('reversal_of_journal_entry_id')->nullable()->constrained('journal_entries');
            $table->timestamps();

            $table->index(['entry_date']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries');
            $table->foreignId('account_id')->constrained('accounts');

            $table->decimal('debit', 12, 2)->default(0);
            $table->decimal('credit', 12, 2)->default(0);

            // Dimensions (subledger hooks)
            $table->foreignId('enrollment_id')->nullable()->constrained('enrollments');
            $table->foreignId('student_id')->nullable()->constrained('students');
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['account_id']);
            $table->index(['enrollment_id']);
            $table->index(['student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('financial_periods');
        Schema::dropIfExists('financial_years');
    }
};

