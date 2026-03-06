<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_fee_ledger', function (Blueprint $table) {
            $table->foreignId('financial_year_id')->nullable()->after('enrollment_id')->constrained('financial_years');
            $table->foreignId('financial_period_id')->nullable()->after('financial_year_id')->constrained('financial_periods');

            // Projection pointers into the General Ledger (GL)
            $table->foreignId('journal_entry_id')->nullable()->after('reversal_of')->constrained('journal_entries');
            $table->foreignId('journal_line_id')->nullable()->after('journal_entry_id')->constrained('journal_lines');

            $table->index(['financial_year_id', 'financial_period_id']);
        });
    }

    public function down(): void
    {
        Schema::table('student_fee_ledger', function (Blueprint $table) {
            $table->dropForeign(['financial_year_id']);
            $table->dropForeign(['financial_period_id']);
            $table->dropForeign(['journal_entry_id']);
            $table->dropForeign(['journal_line_id']);

            $table->dropIndex(['financial_year_id', 'financial_period_id']);

            $table->dropColumn([
                'financial_year_id',
                'financial_period_id',
                'journal_entry_id',
                'journal_line_id',
            ]);
        });
    }
};

