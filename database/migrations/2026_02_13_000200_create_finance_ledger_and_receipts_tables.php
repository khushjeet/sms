<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_fee_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->enum('transaction_type', ['debit', 'credit']);
            $table->string('reference_type', 50);
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->decimal('amount', 12, 2);
            $table->foreignId('posted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at');
            $table->text('narration')->nullable();
            $table->boolean('is_reversal')->default(false);
            $table->foreignId('reversal_of')->nullable()->constrained('student_fee_ledger')->nullOnDelete();
            $table->timestamps();

            $table->index(['enrollment_id', 'posted_at']);
            $table->index(['reference_type', 'reference_id']);
            $table->index('reversal_of');
            $table->index('posted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_fee_ledger');
    }
};
