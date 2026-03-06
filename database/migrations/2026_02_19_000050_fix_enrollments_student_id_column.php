<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('enrollments')) {
            return;
        }

        // Some environments have an incorrect column name `enrollment_id` for the student foreign key.
        // Normalize it to `student_id` to match the codebase.
        if (Schema::hasColumn('enrollments', 'student_id') || !Schema::hasColumn('enrollments', 'enrollment_id')) {
            return;
        }

        try {
            DB::statement('ALTER TABLE enrollments DROP FOREIGN KEY enrollments_student_id_foreign');
        } catch (\Throwable $e) {
            // Ignore if constraint name differs.
        }

        DB::statement('ALTER TABLE enrollments CHANGE enrollment_id student_id BIGINT UNSIGNED NOT NULL');

        try {
            DB::statement('ALTER TABLE enrollments ADD CONSTRAINT enrollments_student_id_foreign FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE');
        } catch (\Throwable $e) {
            // Ignore if already present.
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('enrollments')) {
            return;
        }

        if (Schema::hasColumn('enrollments', 'enrollment_id') || !Schema::hasColumn('enrollments', 'student_id')) {
            return;
        }

        try {
            DB::statement('ALTER TABLE enrollments DROP FOREIGN KEY enrollments_student_id_foreign');
        } catch (\Throwable $e) {
            // Ignore if constraint name differs.
        }

        DB::statement('ALTER TABLE enrollments CHANGE student_id enrollment_id BIGINT UNSIGNED NOT NULL');

        try {
            DB::statement('ALTER TABLE enrollments ADD CONSTRAINT enrollments_student_id_foreign FOREIGN KEY (enrollment_id) REFERENCES students(id) ON DELETE CASCADE');
        } catch (\Throwable $e) {
            // Ignore if already present.
        }
    }
};

