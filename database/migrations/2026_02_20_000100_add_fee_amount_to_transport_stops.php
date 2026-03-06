<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('transport_stops')) {
            return;
        }

        Schema::table('transport_stops', function (Blueprint $table) {
            if (!Schema::hasColumn('transport_stops', 'fee_amount')) {
                $table->decimal('fee_amount', 12, 2)->default(0)->after('stop_name');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('transport_stops')) {
            return;
        }

        Schema::table('transport_stops', function (Blueprint $table) {
            if (Schema::hasColumn('transport_stops', 'fee_amount')) {
                $table->dropColumn('fee_amount');
            }
        });
    }
};

