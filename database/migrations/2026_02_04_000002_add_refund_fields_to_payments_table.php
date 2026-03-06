<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('reversal_of_payment_id')
                ->nullable()
                ->constrained('payments')
                ->nullOnDelete()
                ->after('is_refunded');
            $table->foreignId('refunded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->after('reversal_of_payment_id');
            $table->timestamp('refunded_at')->nullable()->after('refunded_by');
            $table->text('refund_reason')->nullable()->after('refunded_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['reversal_of_payment_id']);
            $table->dropForeign(['refunded_by']);
            $table->dropColumn(['reversal_of_payment_id', 'refunded_by', 'refunded_at', 'refund_reason']);
        });
    }
};
