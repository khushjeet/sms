<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_assignments', function (Blueprint $table) {
            $table->foreignId('discount_approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->after('discount_reason');
            $table->timestamp('discount_approved_at')->nullable()->after('discount_approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('fee_assignments', function (Blueprint $table) {
            $table->dropForeign(['discount_approved_by']);
            $table->dropColumn(['discount_approved_by', 'discount_approved_at']);
        });
    }
};
