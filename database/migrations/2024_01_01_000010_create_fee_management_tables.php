<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Base fee structure per class
        Schema::create('fee_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained()->onDelete('cascade');
            $table->foreignId('academic_year_id')->constrained()->onDelete('cascade');
            $table->string('fee_type'); // Tuition, Admission, Annual, etc.
            $table->decimal('amount', 10, 2);
            $table->enum('frequency', ['one_time', 'monthly', 'quarterly', 'annually'])->default('annually');
            $table->boolean('is_mandatory')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
            
            $table->index(['class_id', 'academic_year_id']);
        });

        // Optional services
        Schema::create('optional_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->onDelete('cascade');
            $table->string('name'); // Transport, Hostel, Meals, Activities
            $table->decimal('amount', 10, 2);
            $table->enum('frequency', ['monthly', 'quarterly', 'annually'])->default('annually');
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });

        // Fee assignment to enrollment
        Schema::create('fee_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->onDelete('cascade');
            $table->decimal('base_fee', 10, 2); // Sum of all mandatory fees
            $table->decimal('optional_services_fee', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0); // Scholarship/discount
            $table->decimal('total_fee', 10, 2); // base + optional - discount
            $table->text('discount_reason')->nullable();
            $table->timestamps();
            
            $table->unique('enrollment_id');
        });

        // Enrollment optional services (many-to-many)
        Schema::create('enrollment_optional_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->onDelete('cascade');
            $table->foreignId('optional_service_id')->constrained()->onDelete('cascade');
            $table->timestamps();
            
            $table->unique(['enrollment_id', 'optional_service_id'], 'enroll_opt_service_unique');
        });

        // Payments
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->onDelete('cascade');
            $table->string('receipt_number')->unique();
            $table->decimal('amount', 10, 2);
            $table->date('payment_date');
            $table->enum('payment_method', ['cash', 'cheque', 'online', 'card', 'upi'])->default('cash');
            $table->string('transaction_id')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('received_by')->constrained('users')->onDelete('cascade');
            $table->boolean('is_refunded')->default(false);
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('receipt_number');
            $table->index('payment_date');
        });

        // Financial holds
        Schema::create('financial_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->onDelete('cascade');
            $table->decimal('outstanding_amount', 10, 2);
            $table->text('reason')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
        Schema::dropIfExists('financial_holds');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('enrollment_optional_services');
        Schema::dropIfExists('fee_assignments');
        Schema::dropIfExists('optional_services');
        Schema::dropIfExists('fee_structures');
    }
};
