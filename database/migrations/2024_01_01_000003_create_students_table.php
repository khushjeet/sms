<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('admission_number')->unique();
            $table->date('admission_date');
            $table->date('date_of_birth');
            $table->enum('gender', ['male', 'female', 'other']);
            $table->string('blood_group')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('pincode')->nullable();
            $table->string('nationality')->default('Indian');
            $table->string('religion')->nullable();
            $table->string('category')->nullable(); // General, OBC, SC, ST
            $table->string('aadhar_number')->nullable()->unique();
            $table->json('medical_info')->nullable(); // Allergies, conditions, etc.
            $table->enum('status', ['active', 'alumni', 'transferred', 'dropped'])->default('active');
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('admission_number');
            $table->index('status');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('payment_receipts') && Schema::hasColumn('payment_receipts', 'student_id')) {
            try {
                Schema::table('payment_receipts', function (Blueprint $table) {
                    $table->dropForeign(['student_id']);
                });
            } catch (Throwable $e) {
                try {
                    DB::statement('ALTER TABLE `payment_receipts` DROP FOREIGN KEY `payment_receipts_student_id_foreign`');
                } catch (Throwable $e) {
                    // no-op
                }
            }
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            Schema::dropIfExists('students');
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            return;
        }

        Schema::dropIfExists('students');
    }
};
