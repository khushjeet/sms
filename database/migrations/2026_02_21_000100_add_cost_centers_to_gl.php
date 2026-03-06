<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // e.g. TRANSPORT, HOSTEL
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->foreignId('cost_center_id')->nullable()->after('account_id')->constrained('cost_centers');
            $table->index(['cost_center_id']);
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropForeign(['cost_center_id']);
            $table->dropIndex(['cost_center_id']);
            $table->dropColumn('cost_center_id');
        });

        Schema::dropIfExists('cost_centers');
    }
};

