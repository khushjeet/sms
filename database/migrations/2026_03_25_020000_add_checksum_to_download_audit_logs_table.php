<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('download_audit_logs', function (Blueprint $table) {
            $table->string('file_checksum', 64)->nullable()->after('file_name');
            $table->index('file_checksum');
        });
    }

    public function down(): void
    {
        Schema::table('download_audit_logs', function (Blueprint $table) {
            $table->dropIndex(['file_checksum']);
            $table->dropColumn('file_checksum');
        });
    }
};
