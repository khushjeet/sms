<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('download_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('module', 80);
            $table->string('report_key', 120);
            $table->string('report_label', 160);
            $table->string('format', 40);
            $table->string('status', 40)->default('completed');
            $table->string('file_name')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->json('filters')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();

            $table->index(['module', 'report_key'], 'download_audit_logs_module_report_idx');
            $table->index(['user_id', 'downloaded_at'], 'download_audit_logs_user_downloaded_idx');
            $table->index('downloaded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_audit_logs');
    }
};
