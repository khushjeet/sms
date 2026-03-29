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
            Schema::table('teacher_marks', function (Blueprint $table) {
                $table->unique(
                    ['enrollment_id', 'subject_id', 'section_id', 'academic_year_id', 'exam_configuration_id', 'teacher_id', 'marked_on'],
                    'teacher_marks_unique_teacher_exam_sheet_row'
                );
            });
        }
        if (!$this->indexExists('teacher_marks', 'teacher_marks_teacher_exam_scope_idx')) {
            Schema::table('teacher_marks', function (Blueprint $table) {
                $table->index(
                    ['teacher_id', 'subject_id', 'section_id', 'academic_year_id', 'exam_configuration_id', 'marked_on'],
                    'teacher_marks_teacher_exam_scope_idx'
                );
            });
        }
        if ($this->indexExists('teacher_marks', 'teacher_marks_unique_teacher_sheet_row')) {
            Schema::table('teacher_marks', function (Blueprint $table) {
                $table->dropUnique('teacher_marks_unique_teacher_sheet_row');
            });
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
            Schema::table('compiled_marks', function (Blueprint $table) {
                $table->unique(
                    ['enrollment_id', 'subject_id', 'section_id', 'academic_year_id', 'exam_configuration_id', 'marked_on'],
                    'compiled_marks_unique_exam_sheet_row'
                );
            });
        }
        if (!$this->indexExists('compiled_marks', 'compiled_marks_exam_sheet_idx')) {
            Schema::table('compiled_marks', function (Blueprint $table) {
                $table->index(
                    ['section_id', 'subject_id', 'academic_year_id', 'exam_configuration_id', 'marked_on'],
                    'compiled_marks_exam_sheet_idx'
                );
            });
        }
        if ($this->indexExists('compiled_marks', 'compiled_marks_unique_sheet_row')) {
            Schema::table('compiled_marks', function (Blueprint $table) {
                $table->dropUnique('compiled_marks_unique_sheet_row');
            });
        }
        if ($this->indexExists('compiled_marks', 'compiled_marks_sheet_idx')) {
            Schema::table('compiled_marks', function (Blueprint $table) {
                $table->dropIndex('compiled_marks_sheet_idx');
            });
        }
    }

    public function down(): void
    {
        if (!$this->indexExists('compiled_marks', 'compiled_marks_enrollment_id_idx')) {
            Schema::table('compiled_marks', function (Blueprint $table) {
                $table->index('enrollment_id', 'compiled_marks_enrollment_id_idx');
            });
        }
        if (!$this->indexExists('compiled_marks', 'compiled_marks_section_id_idx')) {
            Schema::table('compiled_marks', function (Blueprint $table) {
                $table->index('section_id', 'compiled_marks_section_id_idx');
            });
        }
        try {
            Schema::table('compiled_marks', function (Blueprint $table) {
                $table->dropConstrainedForeignId('exam_configuration_id');
            });
        } catch (Throwable $e) {
            // no-op
        }
        if ($this->indexExists('compiled_marks', 'compiled_marks_unique_exam_sheet_row')) {
            Schema::table('compiled_marks', function (Blueprint $table) {
                $table->dropUnique('compiled_marks_unique_exam_sheet_row');
            });
        }
        if ($this->indexExists('compiled_marks', 'compiled_marks_exam_sheet_idx')) {
            Schema::table('compiled_marks', function (Blueprint $table) {
                $table->dropIndex('compiled_marks_exam_sheet_idx');
            });
        }
        $this->deleteDuplicateRows(
            'compiled_marks',
            ['enrollment_id', 'subject_id', 'section_id', 'academic_year_id', 'marked_on']
        );
        if (!$this->indexExists('compiled_marks', 'compiled_marks_unique_sheet_row')) {
            Schema::table('compiled_marks', function (Blueprint $table) {
                $table->unique(
                    ['enrollment_id', 'subject_id', 'section_id', 'academic_year_id', 'marked_on'],
                    'compiled_marks_unique_sheet_row'
                );
            });
        }
        if (!$this->indexExists('compiled_marks', 'compiled_marks_sheet_idx')) {
            Schema::table('compiled_marks', function (Blueprint $table) {
                $table->index(
                    ['section_id', 'subject_id', 'academic_year_id', 'marked_on'],
                    'compiled_marks_sheet_idx'
                );
            });
        }

        try {
            Schema::table('teacher_marks', function (Blueprint $table) {
                $table->dropConstrainedForeignId('exam_configuration_id');
            });
        } catch (Throwable $e) {
            // no-op
        }
        if ($this->indexExists('teacher_marks', 'teacher_marks_unique_teacher_exam_sheet_row')) {
            Schema::table('teacher_marks', function (Blueprint $table) {
                $table->dropUnique('teacher_marks_unique_teacher_exam_sheet_row');
            });
        }
        if ($this->indexExists('teacher_marks', 'teacher_marks_teacher_exam_scope_idx')) {
            Schema::table('teacher_marks', function (Blueprint $table) {
                $table->dropIndex('teacher_marks_teacher_exam_scope_idx');
            });
        }
        $this->deleteDuplicateRows(
            'teacher_marks',
            ['enrollment_id', 'subject_id', 'section_id', 'academic_year_id', 'teacher_id', 'marked_on']
        );
        if (!$this->indexExists('teacher_marks', 'teacher_marks_unique_teacher_sheet_row')) {
            Schema::table('teacher_marks', function (Blueprint $table) {
                $table->unique(
                    ['enrollment_id', 'subject_id', 'section_id', 'academic_year_id', 'teacher_id', 'marked_on'],
                    'teacher_marks_unique_teacher_sheet_row'
                );
            });
        }
        if ($this->indexExists('compiled_marks', 'compiled_marks_enrollment_id_idx')) {
            Schema::table('compiled_marks', function (Blueprint $table) {
                $table->dropIndex('compiled_marks_enrollment_id_idx');
            });
        }
        if ($this->indexExists('compiled_marks', 'compiled_marks_section_id_idx')) {
            Schema::table('compiled_marks', function (Blueprint $table) {
                $table->dropIndex('compiled_marks_section_id_idx');
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$table}')");

            foreach ($indexes as $row) {
                if (($row->name ?? null) === $index) {
                    return true;
                }
            }

            return false;
        }

        $result = DB::selectOne(
            'SELECT COUNT(1) AS c FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $index]
        );

        return ((int) ($result->c ?? 0)) > 0;
    }

    private function deleteDuplicateRows(string $table, array $keyColumns): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

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
