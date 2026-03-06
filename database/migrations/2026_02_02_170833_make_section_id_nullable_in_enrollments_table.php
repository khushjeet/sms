<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * SRS: Section may be null - students can be enrolled in class without section assignment
     */
    public function up(): void
    {
        try {
            Schema::table('enrollments', function (Blueprint $table) {
                // Drop the foreign key constraint first
                $table->dropForeign(['section_id']);
            });
        } catch (Throwable $e) {
            // no-op
        }

        Schema::table('enrollments', function (Blueprint $table) {
            // Make section_id nullable
            $table->foreignId('section_id')->nullable()->change();
        });

        Schema::table('enrollments', function (Blueprint $table) {
            // Re-add foreign key with nullable support
            $table->foreign('section_id')->references('id')->on('sections')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            Schema::table('enrollments', function (Blueprint $table) {
                // Drop the nullable foreign key
                $table->dropForeign(['section_id']);
            });
        } catch (Throwable $e) {
            // no-op
        }

        $defaultSectionId = DB::table('sections')->min('id');
        if ($defaultSectionId === null) {
            DB::table('enrollments')->whereNull('section_id')->delete();
        } else {
            DB::table('enrollments')->whereNull('section_id')->update(['section_id' => $defaultSectionId]);
        }

        Schema::table('enrollments', function (Blueprint $table) {
            // Make section_id required again
            $table->foreignId('section_id')->nullable(false)->change();
        });

        Schema::table('enrollments', function (Blueprint $table) {
            // Re-add required foreign key
            $table->foreign('section_id')->references('id')->on('sections')->onDelete('cascade');
        });
    }
};
