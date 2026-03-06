<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compiled_marks', function (Blueprint $table) {
            $table->foreignId('exam_session_id')
                ->nullable()
                ->after('academic_year_id')
                ->constrained('exam_sessions')
                ->nullOnDelete();

            $table->index(['exam_session_id', 'is_finalized'], 'compiled_marks_session_finalized_idx');
        });
    }

    public function down(): void
    {
        Schema::table('compiled_marks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exam_session_id');
            $table->dropIndex('compiled_marks_session_finalized_idx');
        });
    }
};
