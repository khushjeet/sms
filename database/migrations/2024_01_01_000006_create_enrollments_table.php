<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->onDelete('cascade');
            $table->foreignId('academic_year_id')->constrained()->onDelete('cascade');
            $table->foreignId('section_id')->constrained()->onDelete('cascade');
            $table->integer('roll_number')->nullable();
            $table->date('enrollment_date');
            $table->enum('status', ['active', 'promoted', 'repeated', 'transferred', 'dropped'])->default('active');
            $table->boolean('is_locked')->default(false); // Locked after promotion/transfer
            $table->foreignId('promoted_from_enrollment_id')->nullable()->constrained('enrollments')->onDelete('set null');
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // One enrollment per student per academic year
            $table->unique(['student_id', 'academic_year_id']);
            $table->index('status');
            $table->index('is_locked');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
