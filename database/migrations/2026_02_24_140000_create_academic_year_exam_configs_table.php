<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_year_exam_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('sequence');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['academic_year_id', 'name'], 'ay_exam_configs_year_name_unique');
            $table->unique(['academic_year_id', 'sequence'], 'ay_exam_configs_year_sequence_unique');
            $table->index(['academic_year_id', 'is_active'], 'ay_exam_configs_year_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_year_exam_configs');
    }
};

