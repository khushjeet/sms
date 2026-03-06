<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->foreignId('exam_configuration_id')
                ->nullable()
                ->after('class_id')
                ->constrained('academic_year_exam_configs')
                ->nullOnDelete();

            $table->index(
                ['academic_year_id', 'class_id', 'exam_configuration_id'],
                'exam_sessions_year_class_exam_config_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->dropIndex('exam_sessions_year_class_exam_config_idx');
            $table->dropConstrainedForeignId('exam_configuration_id');
        });
    }
};

