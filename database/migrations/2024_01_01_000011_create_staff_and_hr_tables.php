<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('employee_id')->unique();
            $table->date('joining_date');
            $table->enum('employee_type', ['teaching', 'non_teaching'])->default('teaching');
            $table->string('designation'); // Teacher, Principal, Librarian, etc.
            $table->string('department')->nullable();
            $table->string('qualification')->nullable();
            $table->decimal('salary', 10, 2)->nullable();
            $table->date('date_of_birth');
            $table->enum('gender', ['male', 'female', 'other']);
            $table->text('address')->nullable();
            $table->string('emergency_contact')->nullable();
            $table->string('aadhar_number')->nullable()->unique();
            $table->string('pan_number')->nullable()->unique();
            $table->enum('status', ['active', 'on_leave', 'resigned', 'terminated'])->default('active');
            $table->date('resignation_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('employee_id');
            $table->index('employee_type');
            $table->index('status');
        });

        Schema::create('staff_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->enum('status', ['present', 'absent', 'leave', 'half_day'])->default('present');
            $table->time('check_in')->nullable();
            $table->time('check_out')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            
            $table->unique(['staff_id', 'date']);
            $table->index('date');
        });

        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Sick Leave, Casual Leave, etc.
            $table->integer('max_days_per_year')->default(0);
            $table->boolean('is_paid')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('staff_leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained()->onDelete('cascade');
            $table->foreignId('leave_type_id')->constrained()->onDelete('cascade');
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('total_days');
            $table->text('reason');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('remarks')->nullable();
            $table->timestamps();
            
            $table->index(['staff_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_leaves');
        Schema::dropIfExists('leave_types');
        Schema::dropIfExists('staff_attendances');
        Schema::dropIfExists('staff');
    }
};
