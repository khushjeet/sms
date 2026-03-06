<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_heads', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->nullable()->unique();
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });

        Schema::create('fee_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_head_id')->constrained('fee_heads')->restrictOnDelete();
            $table->foreignId('class_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->date('due_date');
            $table->decimal('amount', 12, 2);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->index(['class_id', 'academic_year_id']);
        });

        Schema::create('enrollment_fee_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('fee_installment_id')->constrained('fee_installments')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['enrollment_id', 'fee_installment_id'], 'efi_enrollment_installment_unique');
            $table->index('fee_installment_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('student_fee_installments') && Schema::hasColumn('student_fee_installments', 'fee_installment_id')) {
            Schema::table('student_fee_installments', function (Blueprint $table) {
                $table->dropForeign(['fee_installment_id']);
            });
        }

        Schema::dropIfExists('enrollment_fee_installments');
        Schema::dropIfExists('fee_installments');
        Schema::dropIfExists('fee_heads');
    }
};
