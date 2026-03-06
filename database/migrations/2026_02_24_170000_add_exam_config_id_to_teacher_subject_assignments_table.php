<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_subject_assignments', function (Blueprint $table) {
            $table->foreignId('academic_year_exam_config_id')
                ->nullable()
                ->after('academic_year_id')
                ->constrained('academic_year_exam_configs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('teacher_subject_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('academic_year_exam_config_id');
        });
    }
};
