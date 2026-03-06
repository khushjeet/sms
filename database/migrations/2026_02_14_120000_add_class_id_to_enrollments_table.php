<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->foreignId('class_id')
                ->nullable()
                ->after('academic_year_id')
                ->constrained('classes')
                ->nullOnDelete();
            $table->index(['academic_year_id', 'class_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            // Backfill from section -> class mapping
            DB::statement("
                UPDATE enrollments e
                INNER JOIN sections s ON s.id = e.section_id
                SET e.class_id = s.class_id
                WHERE e.class_id IS NULL
            ");

            // Fallback backfill from student profile for same academic year
            DB::statement("
                UPDATE enrollments e
                INNER JOIN student_profiles sp
                    ON sp.student_id = e.student_id
                    AND sp.academic_year_id = e.academic_year_id
                SET e.class_id = sp.class_id
                WHERE e.class_id IS NULL
            ");
        }
    }

    public function down(): void
    {
        if (!$this->indexExists('enrollments', 'enrollments_academic_year_id_idx')) {
            DB::statement('ALTER TABLE `enrollments` ADD INDEX `enrollments_academic_year_id_idx` (`academic_year_id`)');
        }

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropForeign(['class_id']);
        });

        if ($this->indexExists('enrollments', 'enrollments_academic_year_id_class_id_index')) {
            DB::statement('ALTER TABLE `enrollments` DROP INDEX `enrollments_academic_year_id_class_id_index`');
        }

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropColumn('class_id');
        });
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
