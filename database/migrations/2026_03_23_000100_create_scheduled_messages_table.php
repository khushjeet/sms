<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_messages', function (Blueprint $table) {
            $table->id();
            $table->string('language', 20)->default('english');
            $table->string('channel', 20)->default('email');
            $table->string('audience', 20)->default('parents');
            $table->string('subject')->nullable();
            $table->text('message');
            $table->json('student_ids');
            $table->timestamp('scheduled_for');
            $table->string('status', 20)->default('scheduled');
            $table->string('batch_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_messages');
    }
};
