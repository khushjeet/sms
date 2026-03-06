<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('enrollment_fee_installments')) {
            Schema::create('enrollment_fee_installments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
                $table->foreignId('fee_installment_id')->constrained('fee_installments')->restrictOnDelete();
                $table->decimal('amount', 12, 2);
                $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();

                $table->unique(['enrollment_id', 'fee_installment_id'], 'efi_enrollment_installment_unique');
                $table->index('fee_installment_id');
            });
        }

        if (!Schema::hasTable('student_fee_installments')) {
            return;
        }

        $studentColumn = Schema::hasColumn('enrollments', 'student_id') ? 'student_id' : (Schema::hasColumn('enrollments', 'enrollment_id') ? 'enrollment_id' : null);
        if (!$studentColumn) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                INSERT INTO enrollment_fee_installments
                    (enrollment_id, fee_installment_id, amount, assigned_by, created_at, updated_at)
                SELECT
                    e.id as enrollment_id,
                    sfi.fee_installment_id,
                    sfi.amount,
                    sfi.assigned_by,
                    sfi.created_at,
                    sfi.updated_at
                FROM student_fee_installments sfi
                INNER JOIN enrollments e
                    ON e.{$studentColumn} = sfi.student_id
                    AND e.academic_year_id = sfi.academic_year_id
                LEFT JOIN enrollment_fee_installments efi
                    ON efi.enrollment_id = e.id
                    AND efi.fee_installment_id = sfi.fee_installment_id
                WHERE efi.id IS NULL
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_fee_installments');
    }
};
