<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compiled_marks', function (Blueprint $table) {
            $table->dropForeign(['section_id']);
        });

        Schema::table('compiled_marks', function (Blueprint $table) {
            $table->foreignId('section_id')->nullable()->change();
        });

        Schema::table('compiled_marks', function (Blueprint $table) {
            $table->foreign('section_id')->references('id')->on('sections')->restrictOnDelete();
        });

        Schema::table('compiled_mark_histories', function (Blueprint $table) {
            $table->dropForeign(['section_id']);
        });

        Schema::table('compiled_mark_histories', function (Blueprint $table) {
            $table->foreignId('section_id')->nullable()->change();
        });

        Schema::table('compiled_mark_histories', function (Blueprint $table) {
            $table->foreign('section_id')->references('id')->on('sections')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('compiled_mark_histories', function (Blueprint $table) {
            $table->dropForeign(['section_id']);
        });

        Schema::table('compiled_mark_histories', function (Blueprint $table) {
            $table->foreignId('section_id')->nullable(false)->change();
        });

        Schema::table('compiled_mark_histories', function (Blueprint $table) {
            $table->foreign('section_id')->references('id')->on('sections')->restrictOnDelete();
        });

        Schema::table('compiled_marks', function (Blueprint $table) {
            $table->dropForeign(['section_id']);
        });

        Schema::table('compiled_marks', function (Blueprint $table) {
            $table->foreignId('section_id')->nullable(false)->change();
        });

        Schema::table('compiled_marks', function (Blueprint $table) {
            $table->foreign('section_id')->references('id')->on('sections')->restrictOnDelete();
        });
    }
};
