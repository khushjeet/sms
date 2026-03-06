<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_monthly_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->char('month', 7); // YYYY-MM
            $table->unsignedInteger('present_count')->default(0);
            $table->unsignedInteger('absent_count')->default(0);
            $table->unsignedInteger('leave_count')->default(0);
            $table->unsignedInteger('half_day_count')->default(0);
            $table->unsignedInteger('total_count')->default(0);
            $table->decimal('attendance_percentage', 6, 2)->default(0);
            $table->timestamps();

            $table->unique(['enrollment_id', 'month'], 'attendance_monthly_unique_enrollment_month');
            $table->index(['academic_year_id', 'month'], 'attendance_monthly_year_month_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_monthly_summaries');
    }
};
