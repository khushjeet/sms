<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_marks', function (Blueprint $table) {
            $table->index('enrollment_id', 'teacher_marks_enrollment_id_idx');
            $table->dropUnique('teacher_marks_unique_sheet_row');
            $table->unique(
                ['enrollment_id', 'subject_id', 'section_id', 'academic_year_id', 'teacher_id', 'marked_on'],
                'teacher_marks_unique_teacher_sheet_row'
            );
        });
    }

    public function down(): void
    {
        DB::statement(
            'DELETE t1 FROM `teacher_marks` t1
             INNER JOIN `teacher_marks` t2
                ON t1.`enrollment_id` <=> t2.`enrollment_id`
               AND t1.`subject_id` <=> t2.`subject_id`
               AND t1.`academic_year_id` <=> t2.`academic_year_id`
               AND t1.`marked_on` <=> t2.`marked_on`
               AND t1.id > t2.id'
        );

        if ($this->indexExists('teacher_marks', 'teacher_marks_unique_teacher_sheet_row')) {
            DB::statement('ALTER TABLE `teacher_marks` DROP INDEX `teacher_marks_unique_teacher_sheet_row`');
        }
        if (!$this->indexExists('teacher_marks', 'teacher_marks_unique_sheet_row')) {
            DB::statement('ALTER TABLE `teacher_marks` ADD UNIQUE `teacher_marks_unique_sheet_row` (`enrollment_id`, `subject_id`, `academic_year_id`, `marked_on`)');
        }
        if ($this->indexExists('teacher_marks', 'teacher_marks_enrollment_id_idx')) {
            DB::statement('ALTER TABLE `teacher_marks` DROP INDEX `teacher_marks_enrollment_id_idx`');
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
