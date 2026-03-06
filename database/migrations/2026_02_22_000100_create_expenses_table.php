<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('expense_number')->unique();
            $table->date('expense_date');
            $table->string('category');
            $table->text('description')->nullable();
            $table->string('vendor_name')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('payment_method')->default('cash');
            $table->string('payment_account_code')->nullable();
            $table->string('expense_account_code');
            $table->string('reference_number')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_reversal')->default(false);
            $table->foreignId('reversal_of_expense_id')->nullable()->constrained('expenses');
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            $table->unique('reversal_of_expense_id');
            $table->index(['expense_date', 'is_reversal']);
            $table->index('category');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
