<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('teacher_subject_assignments')) {
            Schema::create('teacher_subject_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('teacher_id')->constrained('users')->onDelete('cascade');
                $table->foreignId('class_id')->constrained()->onDelete('cascade');
                $table->foreignId('section_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('subject_id')->constrained()->onDelete('cascade');
                $table->foreignId('academic_year_id')->constrained()->onDelete('cascade');
                $table->foreignId('academic_year_exam_config_id')
                    ->nullable()
                    ->constrained('academic_year_exam_configs')
                    ->nullOnDelete();
                $table->timestamps();

                $table->unique(
                    ['teacher_id', 'class_id', 'section_id', 'subject_id', 'academic_year_id'],
                    'teacher_class_section_subject_year_unique'
                );
            });

            return;
        }

        if (!Schema::hasColumn('teacher_subject_assignments', 'class_id')) {
            Schema::table('teacher_subject_assignments', function (Blueprint $table) {
                $table->foreignId('class_id')->nullable()->after('teacher_id')->constrained()->cascadeOnDelete();
            });
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::table('teacher_subject_assignments')
                ->whereNull('class_id')
                ->orderBy('id')
                ->get(['id', 'section_id'])
                ->each(function ($assignment): void {
                    $classId = DB::table('sections')
                        ->where('id', $assignment->section_id)
                        ->value('class_id');

                    if ($classId !== null) {
                        DB::table('teacher_subject_assignments')
                            ->where('id', $assignment->id)
                            ->update(['class_id' => $classId]);
                    }
                });
        } else {
            DB::statement('
                UPDATE teacher_subject_assignments tsa
                INNER JOIN sections s ON s.id = tsa.section_id
                SET tsa.class_id = s.class_id
                WHERE tsa.class_id IS NULL
            ');
        }

        Schema::table('teacher_subject_assignments', function (Blueprint $table) {
            $table->dropForeign(['section_id']);
        });

        Schema::table('teacher_subject_assignments', function (Blueprint $table) {
            $table->foreignId('section_id')->nullable()->change();
            $table->foreign('section_id')->references('id')->on('sections')->nullOnDelete();
        });

        if (!$this->hasIndex('teacher_subject_assignments', 'teacher_class_section_subject_year_unique')) {
            Schema::table('teacher_subject_assignments', function (Blueprint $table) {
                $table->unique(
                    ['teacher_id', 'class_id', 'section_id', 'subject_id', 'academic_year_id'],
                    'teacher_class_section_subject_year_unique'
                );
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('teacher_subject_assignments')) {
            return;
        }

        if (!Schema::hasColumn('teacher_subject_assignments', 'class_id')) {
            return;
        }

        Schema::table('teacher_subject_assignments', function (Blueprint $table) {
            $table->dropForeign(['section_id']);
        });

        $fallbackSectionId = DB::table('sections')->value('id');
        if ($fallbackSectionId) {
            DB::table('teacher_subject_assignments')
                ->whereNull('section_id')
                ->update(['section_id' => $fallbackSectionId]);
        }

        if ($this->hasIndex('teacher_subject_assignments', 'teacher_class_section_subject_year_unique')) {
            Schema::table('teacher_subject_assignments', function (Blueprint $table) {
                $table->dropUnique('teacher_class_section_subject_year_unique');
            });
        }

        Schema::table('teacher_subject_assignments', function (Blueprint $table) {
            $table->foreignId('section_id')->nullable(false)->change();
            $table->foreign('section_id')->references('id')->on('sections')->cascadeOnDelete();
            $table->dropConstrainedForeignId('class_id');
        });

        if (!$this->hasIndex('teacher_subject_assignments', 'teacher_section_subject_year_unique')) {
            Schema::table('teacher_subject_assignments', function (Blueprint $table) {
                $table->unique(['teacher_id', 'section_id', 'subject_id', 'academic_year_id'], 'teacher_section_subject_year_unique');
            });
        }
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$table}')");

            return collect($indexes)->contains(fn ($index) => ($index->name ?? null) === $indexName);
        }

        $indexes = DB::select('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?', [$indexName]);

        return !empty($indexes);
    }
};
