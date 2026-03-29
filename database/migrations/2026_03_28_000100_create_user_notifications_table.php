<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->nullable()->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 50)->nullable()->index();
            $table->unsignedBigInteger('class_id')->nullable()->index();
            $table->unsignedBigInteger('section_id')->nullable()->index();
            $table->string('audience_type', 50)->nullable()->index();
            $table->string('title', 191);
            $table->text('message');
            $table->string('type', 50)->index();
            $table->string('priority', 30)->default('normal')->index();
            $table->string('entity_type', 100)->nullable()->index();
            $table->unsignedBigInteger('entity_id')->nullable()->index();
            $table->string('action_target', 255)->nullable();
            $table->json('meta')->nullable();
            $table->boolean('is_read')->default(false)->index();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_read', 'created_at'], 'user_notifications_user_read_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
