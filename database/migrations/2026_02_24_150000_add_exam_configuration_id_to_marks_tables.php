<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('teacher_marks', 'exam_configuration_id')) {
            Schema::table('teacher_marks', function (Blueprint $table) {
                $table->foreignId('exam_configuration_id')
                    ->nullable()
                    ->after('academic_year_id')
                    ->constrained('academic_year_exam_configs')
                    ->nullOnDelete();
            });
        }

        if (!$this->indexExists('teacher_marks', 'teacher_marks_unique_teacher_exam_sheet_row')) {
            DB::statement('ALTER TABLE `teacher_marks` ADD UNIQUE `teacher_marks_unique_teacher_exam_sheet_row` (`enrollment_id`, `subject_id`, `section_id`, `academic_year_id`, `exam_configuration_id`, `teacher_id`, `marked_on`)');
        }
        if (!$this->indexExists('teacher_marks', 'teacher_marks_teacher_exam_scope_idx')) {
            DB::statement('ALTER TABLE `teacher_marks` ADD INDEX `teacher_marks_teacher_exam_scope_idx` (`teacher_id`, `subject_id`, `section_id`, `academic_year_id`, `exam_configuration_id`, `marked_on`)');
        }
        if ($this->indexExists('teacher_marks', 'teacher_marks_unique_teacher_sheet_row')) {
            DB::statement('ALTER TABLE `teacher_marks` DROP INDEX `teacher_marks_unique_teacher_sheet_row`');
        }

        if (!Schema::hasColumn('compiled_marks', 'exam_configuration_id')) {
            Schema::table('compiled_marks', function (Blueprint $table) {
                $table->foreignId('exam_configuration_id')
                    ->nullable()
                    ->after('academic_year_id')
                    ->constrained('academic_year_exam_configs')
                    ->nullOnDelete();
            });
        }

        if (!$this->indexExists('compiled_marks', 'compiled_marks_unique_exam_sheet_row')) {
            DB::statement('ALTER TABLE `compiled_marks` ADD UNIQUE `compiled_marks_unique_exam_sheet_row` (`enrollment_id`, `subject_id`, `section_id`, `academic_year_id`, `exam_configuration_id`, `marked_on`)');
        }
        if (!$this->indexExists('compiled_marks', 'compiled_marks_exam_sheet_idx')) {
            DB::statement('ALTER TABLE `compiled_marks` ADD INDEX `compiled_marks_exam_sheet_idx` (`section_id`, `subject_id`, `academic_year_id`, `exam_configuration_id`, `marked_on`)');
        }
        if ($this->indexExists('compiled_marks', 'compiled_marks_unique_sheet_row')) {
            DB::statement('ALTER TABLE `compiled_marks` DROP INDEX `compiled_marks_unique_sheet_row`');
        }
        if ($this->indexExists('compiled_marks', 'compiled_marks_sheet_idx')) {
            DB::statement('ALTER TABLE `compiled_marks` DROP INDEX `compiled_marks_sheet_idx`');
        }
    }

    public function down(): void
    {
        if (!$this->indexExists('compiled_marks', 'compiled_marks_enrollment_id_idx')) {
            DB::statement('ALTER TABLE `compiled_marks` ADD INDEX `compiled_marks_enrollment_id_idx` (`enrollment_id`)');
        }
        if (!$this->indexExists('compiled_marks', 'compiled_marks_section_id_idx')) {
            DB::statement('ALTER TABLE `compiled_marks` ADD INDEX `compiled_marks_section_id_idx` (`section_id`)');
        }
        try {
            Schema::table('compiled_marks', function (Blueprint $table) {
                $table->dropConstrainedForeignId('exam_configuration_id');
            });
        } catch (Throwable $e) {
            // no-op
        }
        if ($this->indexExists('compiled_marks', 'compiled_marks_unique_exam_sheet_row')) {
            DB::statement('ALTER TABLE `compiled_marks` DROP INDEX `compiled_marks_unique_exam_sheet_row`');
        }
        if ($this->indexExists('compiled_marks', 'compiled_marks_exam_sheet_idx')) {
            DB::statement('ALTER TABLE `compiled_marks` DROP INDEX `compiled_marks_exam_sheet_idx`');
        }
        $this->deleteDuplicateRows(
            'compiled_marks',
            ['enrollment_id', 'subject_id', 'section_id', 'academic_year_id', 'marked_on']
        );
        if (!$this->indexExists('compiled_marks', 'compiled_marks_unique_sheet_row')) {
            DB::statement('ALTER TABLE `compiled_marks` ADD UNIQUE `compiled_marks_unique_sheet_row` (`enrollment_id`, `subject_id`, `section_id`, `academic_year_id`, `marked_on`)');
        }
        if (!$this->indexExists('compiled_marks', 'compiled_marks_sheet_idx')) {
            DB::statement('ALTER TABLE `compiled_marks` ADD INDEX `compiled_marks_sheet_idx` (`section_id`, `subject_id`, `academic_year_id`, `marked_on`)');
        }

        try {
            Schema::table('teacher_marks', function (Blueprint $table) {
                $table->dropConstrainedForeignId('exam_configuration_id');
            });
        } catch (Throwable $e) {
            // no-op
        }
        if ($this->indexExists('teacher_marks', 'teacher_marks_unique_teacher_exam_sheet_row')) {
            DB::statement('ALTER TABLE `teacher_marks` DROP INDEX `teacher_marks_unique_teacher_exam_sheet_row`');
        }
        if ($this->indexExists('teacher_marks', 'teacher_marks_teacher_exam_scope_idx')) {
            DB::statement('ALTER TABLE `teacher_marks` DROP INDEX `teacher_marks_teacher_exam_scope_idx`');
        }
        $this->deleteDuplicateRows(
            'teacher_marks',
            ['enrollment_id', 'subject_id', 'section_id', 'academic_year_id', 'teacher_id', 'marked_on']
        );
        if (!$this->indexExists('teacher_marks', 'teacher_marks_unique_teacher_sheet_row')) {
            DB::statement('ALTER TABLE `teacher_marks` ADD UNIQUE `teacher_marks_unique_teacher_sheet_row` (`enrollment_id`, `subject_id`, `section_id`, `academic_year_id`, `teacher_id`, `marked_on`)');
        }
        if ($this->indexExists('compiled_marks', 'compiled_marks_enrollment_id_idx')) {
            DB::statement('ALTER TABLE `compiled_marks` DROP INDEX `compiled_marks_enrollment_id_idx`');
        }
        if ($this->indexExists('compiled_marks', 'compiled_marks_section_id_idx')) {
            DB::statement('ALTER TABLE `compiled_marks` DROP INDEX `compiled_marks_section_id_idx`');
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

    private function deleteDuplicateRows(string $table, array $keyColumns): void
    {
        $joinConditions = implode(
            ' AND ',
            array_map(
                fn (string $column): string => "t1.`{$column}` <=> t2.`{$column}`",
                $keyColumns
            )
        );

        DB::statement("DELETE t1 FROM `{$table}` t1 INNER JOIN `{$table}` t2 ON {$joinConditions} AND t1.id > t2.id");
    }
};
