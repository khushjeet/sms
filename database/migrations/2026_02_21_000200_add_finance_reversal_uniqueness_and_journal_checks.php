<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureNoDuplicateReversals();

        Schema::table('payments', function (Blueprint $table) {
            $table->unique('reversal_of_payment_id', 'payments_unique_reversal_of_payment_id');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->unique('reversal_of_journal_entry_id', 'journal_entries_unique_reversal_of_journal_entry_id');
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Enforce non-negative debit/credit.
        DB::statement("ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_non_negative_chk CHECK (debit >= 0 AND credit >= 0)");

        // Enforce exactly one side for each row (no zero/zero and no both-positive lines).
        DB::statement("ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_one_side_chk CHECK (((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0)))");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            try {
                DB::statement('ALTER TABLE journal_lines DROP CHECK journal_lines_non_negative_chk');
            } catch (Throwable $e) {
                // no-op
            }

            try {
                DB::statement('ALTER TABLE journal_lines DROP CHECK journal_lines_one_side_chk');
            } catch (Throwable $e) {
                // no-op
            }
        }

        if (!$this->indexExists('journal_entries', 'journal_entries_reversal_of_journal_entry_id_idx')) {
            DB::statement('ALTER TABLE `journal_entries` ADD INDEX `journal_entries_reversal_of_journal_entry_id_idx` (`reversal_of_journal_entry_id`)');
        }
        if ($this->indexExists('journal_entries', 'journal_entries_unique_reversal_of_journal_entry_id')) {
            DB::statement('ALTER TABLE `journal_entries` DROP INDEX `journal_entries_unique_reversal_of_journal_entry_id`');
        }

        if (!$this->indexExists('payments', 'payments_reversal_of_payment_id_idx')) {
            DB::statement('ALTER TABLE `payments` ADD INDEX `payments_reversal_of_payment_id_idx` (`reversal_of_payment_id`)');
        }
        if ($this->indexExists('payments', 'payments_unique_reversal_of_payment_id')) {
            DB::statement('ALTER TABLE `payments` DROP INDEX `payments_unique_reversal_of_payment_id`');
        }
    }

    private function ensureNoDuplicateReversals(): void
    {
        $duplicatePaymentReversals = DB::table('payments')
            ->selectRaw('1')
            ->whereNotNull('reversal_of_payment_id')
            ->groupBy('reversal_of_payment_id')
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->exists();

        if ($duplicatePaymentReversals) {
            throw new RuntimeException('Cannot apply unique reversal constraint: duplicate payment reversals exist. Clean data first.');
        }

        $duplicateJournalReversals = DB::table('journal_entries')
            ->selectRaw('1')
            ->whereNotNull('reversal_of_journal_entry_id')
            ->groupBy('reversal_of_journal_entry_id')
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->exists();

        if ($duplicateJournalReversals) {
            throw new RuntimeException('Cannot apply unique reversal constraint: duplicate journal reversals exist. Clean data first.');
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $result = DB::selectOne(
            'SELECT COUNT(1) AS c FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $index]
        );

        return ((int) ($result->c ?? 0)) > 0;
    }
};
