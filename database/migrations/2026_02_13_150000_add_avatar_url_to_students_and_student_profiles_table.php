<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            if (!Schema::hasColumn('students', 'avatar_url')) {
                $table->string('avatar_url')->nullable()->after('medical_info');
            }
        });

        Schema::table('student_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('student_profiles', 'avatar_url')) {
                $table->string('avatar_url')->nullable()->after('user_id');
            }
        });

        if (DB::getDriverName() === 'mysql' && Schema::hasColumn('students', 'avatar_url')) {
            DB::statement("
                UPDATE students s
                JOIN users u ON u.id = s.user_id
                SET s.avatar_url = COALESCE(s.avatar_url, u.avatar)
                WHERE u.avatar IS NOT NULL
            ");
        }

        if (DB::getDriverName() === 'mysql' && Schema::hasColumn('student_profiles', 'avatar_url')) {
            DB::statement("
                UPDATE student_profiles sp
                JOIN students s ON s.id = sp.student_id
                JOIN users u ON u.id = s.user_id
                SET sp.avatar_url = COALESCE(sp.avatar_url, u.avatar)
                WHERE u.avatar IS NOT NULL
            ");
        }
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('student_profiles', 'avatar_url')) {
                $table->dropColumn('avatar_url');
            }
        });

        Schema::table('students', function (Blueprint $table) {
            if (Schema::hasColumn('students', 'avatar_url')) {
                $table->dropColumn('avatar_url');
            }
        });
    }
};
