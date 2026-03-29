<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('students')) {
            Schema::table('students', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            });
        }

        if (Schema::hasTable('parents')) {
            Schema::table('parents', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            });
        }

        if (Schema::hasTable('student_parent')) {
            Schema::table('student_parent', function (Blueprint $table) {
                $table->dropForeign(['student_id']);
                $table->dropForeign(['parent_id']);
                $table->foreign('student_id')->references('id')->on('students')->restrictOnDelete();
                $table->foreign('parent_id')->references('id')->on('parents')->restrictOnDelete();
            });
        }

        if (Schema::hasTable('sections')) {
            Schema::table('sections', function (Blueprint $table) {
                $table->dropForeign(['class_id']);
                $table->dropForeign(['academic_year_id']);
                $table->foreign('class_id')->references('id')->on('classes')->restrictOnDelete();
                $table->foreign('academic_year_id')->references('id')->on('academic_years')->restrictOnDelete();
            });
        }

        if (Schema::hasTable('enrollments')) {
            Schema::table('enrollments', function (Blueprint $table) {
                $table->dropForeign(['student_id']);
                $table->dropForeign(['academic_year_id']);
                $table->dropForeign(['section_id']);
                $table->foreign('student_id')->references('id')->on('students')->restrictOnDelete();
                $table->foreign('academic_year_id')->references('id')->on('academic_years')->restrictOnDelete();
                $table->foreign('section_id')->references('id')->on('sections')->restrictOnDelete();
            });
        }

        if (Schema::hasTable('attendances')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->dropForeign(['enrollment_id']);
                $table->foreign('enrollment_id')->references('id')->on('enrollments')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('attendances')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->dropForeign(['enrollment_id']);
                $table->foreign('enrollment_id')->references('id')->on('enrollments')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('enrollments')) {
            Schema::table('enrollments', function (Blueprint $table) {
                $table->dropForeign(['student_id']);
                $table->dropForeign(['academic_year_id']);
                $table->dropForeign(['section_id']);
                $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
                $table->foreign('academic_year_id')->references('id')->on('academic_years')->cascadeOnDelete();
                $table->foreign('section_id')->references('id')->on('sections')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('sections')) {
            Schema::table('sections', function (Blueprint $table) {
                $table->dropForeign(['class_id']);
                $table->dropForeign(['academic_year_id']);
                $table->foreign('class_id')->references('id')->on('classes')->cascadeOnDelete();
                $table->foreign('academic_year_id')->references('id')->on('academic_years')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('student_parent')) {
            Schema::table('student_parent', function (Blueprint $table) {
                $table->dropForeign(['student_id']);
                $table->dropForeign(['parent_id']);
                $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
                $table->foreign('parent_id')->references('id')->on('parents')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('parents')) {
            Schema::table('parents', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('students')) {
            Schema::table('students', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }
};
