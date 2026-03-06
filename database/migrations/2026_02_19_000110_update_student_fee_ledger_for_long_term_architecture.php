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
            return;
        }

        if (!Schema::hasColumn('student_fee_ledger', 'narration')) {
            Schema::table('student_fee_ledger', function (Blueprint $table) {
                $table->text('narration')->nullable()->after('posted_at');
            });
        }

        // Convert enum reference_type -> varchar so we can safely add new reference types (discount, fee_assignment, special_fee, etc.)
        // Using raw SQL to avoid requiring doctrine/dbal.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE student_fee_ledger MODIFY reference_type VARCHAR(50) NOT NULL");
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('student_fee_ledger')) {
            return;
        }

        if (Schema::hasColumn('student_fee_ledger', 'narration')) {
            Schema::table('student_fee_ledger', function (Blueprint $table) {
                $table->dropColumn('narration');
            });
        }
    }
};
