<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('student_fee_ledger')) {
            $this->createNewLedgerTable();
            $this->ensureNewLedgerConstraintsAndIndexes();
            return;
        }

        if (Schema::hasColumn('student_fee_ledger', 'enrollment_id')) {
            $this->ensureNewLedgerConstraintsAndIndexes();
            $this->backfillFromLegacyIfPresent();
            return;
        }

        $legacyTable = 'student_fee_ledger_legacy';
        if (Schema::hasTable($legacyTable)) {
            $legacyTable = 'student_fee_ledger_legacy_2';
            if (!Schema::hasTable($legacyTable)) {
                Schema::rename('student_fee_ledger', $legacyTable);
            }
        } else {
            Schema::rename('student_fee_ledger', $legacyTable);
        }

        $this->createNewLedgerTable();
        $this->ensureNewLedgerConstraintsAndIndexes();

        $this->backfillFromLegacyIfPresent($legacyTable);
    }

    private function backfillFromLegacyIfPresent(?string $legacyTable = null): void
    {
        $legacyTable ??= Schema::hasTable('student_fee_ledger_legacy') ? 'student_fee_ledger_legacy' : null;
        if (!$legacyTable || !Schema::hasTable($legacyTable)) {
            return;
        }

        // Best-effort backfill: anchor rows to enrollments using (student_id, academic_year_id),
        // then fall back to most recent enrollment by student.
        $enrollmentIdExpr = "COALESCE(
            (SELECT e.id FROM enrollments e
             WHERE e.student_id = sfl.student_id
               AND e.academic_year_id = sfl.academic_year_id
               AND e.deleted_at IS NULL
             ORDER BY e.id
             LIMIT 1),
            (SELECT e.id FROM enrollments e
             WHERE e.student_id = sfl.student_id
               AND e.deleted_at IS NULL
             ORDER BY e.academic_year_id DESC, e.id DESC
             LIMIT 1)
        )";

        DB::statement("
            INSERT INTO student_fee_ledger
                (id, enrollment_id, transaction_type, reference_type, reference_id, amount, posted_by, posted_at, narration, is_reversal, reversal_of, created_at, updated_at)
            SELECT
                sfl.id,
                {$enrollmentIdExpr} AS enrollment_id,
                CASE
                    WHEN sfl.transaction_type = 'adjustment' THEN 'credit'
                    ELSE sfl.transaction_type
                END AS transaction_type,
                sfl.reference_type,
                sfl.reference_id,
                sfl.amount,
                sfl.posted_by,
                sfl.posted_at,
                sfl.narration,
                sfl.is_reversal,
                sfl.reversal_of,
                sfl.created_at,
                sfl.updated_at
            FROM {$legacyTable} sfl
            WHERE {$enrollmentIdExpr} IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM student_fee_ledger n WHERE n.id = sfl.id)
        ");
    }

    public function down(): void
    {
        if (Schema::hasTable('student_fee_ledger_legacy') && Schema::hasTable('student_fee_ledger')) {
            Schema::drop('student_fee_ledger');
            Schema::rename('student_fee_ledger_legacy', 'student_fee_ledger');
            return;
        }

        Schema::dropIfExists('student_fee_ledger');
    }

    private function createNewLedgerTable(): void
    {
        Schema::create('student_fee_ledger', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('enrollment_id');
            $table->enum('transaction_type', ['debit', 'credit']);
            $table->string('reference_type', 50);
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->decimal('amount', 12, 2);
            $table->unsignedBigInteger('posted_by');
            $table->timestamp('posted_at');
            $table->text('narration')->nullable();
            $table->boolean('is_reversal')->default(false);
            $table->unsignedBigInteger('reversal_of')->nullable();
            $table->timestamps();
        });
    }

    private function ensureNewLedgerConstraintsAndIndexes(): void
    {
        if (!Schema::hasTable('student_fee_ledger')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            Schema::table('student_fee_ledger', function (Blueprint $table) {
                // SQLite and other drivers used in tests are handled with table-level definitions.
                // Keep this migration driver-safe by skipping MySQL-specific information_schema logic.
                $table->index(['enrollment_id', 'posted_at'], 'sfl_enrollment_posted_at_idx');
                $table->index(['reference_type', 'reference_id'], 'sfl_reference_idx');
                $table->index('reversal_of', 'sfl_reversal_of_idx');
                $table->index('posted_at', 'sfl_posted_at_idx');
            });
            return;
        }

        $indexes = collect(DB::select("SHOW INDEX FROM student_fee_ledger"))
            ->pluck('Key_name')
            ->unique()
            ->values()
            ->all();
        $indexSet = array_fill_keys($indexes, true);

        Schema::table('student_fee_ledger', function (Blueprint $table) use ($indexSet) {
            if (!isset($indexSet['sfl_enrollment_posted_at_idx'])) {
                $table->index(['enrollment_id', 'posted_at'], 'sfl_enrollment_posted_at_idx');
            }
            if (!isset($indexSet['sfl_reference_idx'])) {
                $table->index(['reference_type', 'reference_id'], 'sfl_reference_idx');
            }
            if (!isset($indexSet['sfl_reversal_of_idx'])) {
                $table->index('reversal_of', 'sfl_reversal_of_idx');
            }
            if (!isset($indexSet['sfl_posted_at_idx'])) {
                $table->index('posted_at', 'sfl_posted_at_idx');
            }
        });

        $constraints = collect(DB::select("
            SELECT CONSTRAINT_NAME, COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'student_fee_ledger'
              AND REFERENCED_TABLE_NAME IS NOT NULL
        "));
        $constraintByColumn = [];
        foreach ($constraints as $row) {
            $constraintByColumn[$row->COLUMN_NAME] = $row->CONSTRAINT_NAME;
        }

        if (!isset($constraintByColumn['enrollment_id'])) {
            DB::statement("
                ALTER TABLE student_fee_ledger
                ADD CONSTRAINT sfl_enrollment_id_fk
                FOREIGN KEY (enrollment_id) REFERENCES enrollments(id)
                ON DELETE RESTRICT
            ");
        }

        if (!isset($constraintByColumn['posted_by'])) {
            DB::statement("
                ALTER TABLE student_fee_ledger
                ADD CONSTRAINT sfl_posted_by_fk
                FOREIGN KEY (posted_by) REFERENCES users(id)
                ON DELETE RESTRICT
            ");
        }

        if (!isset($constraintByColumn['reversal_of'])) {
            DB::statement("
                ALTER TABLE student_fee_ledger
                ADD CONSTRAINT sfl_reversal_of_fk
                FOREIGN KEY (reversal_of) REFERENCES student_fee_ledger(id)
                ON DELETE SET NULL
            ");
        }
    }
};
