<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use App\Models\Student;
use App\Models\StudentProfile;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('student_profiles', 'user_id')) {
                $table->foreignId('user_id')->nullable()->after('student_id')->constrained()->nullOnDelete();
            }

            if (!Schema::hasColumn('student_profiles', 'father_mobile_number')) {
                $table->string('father_mobile_number', 20)->nullable()->after('father_email');
            }

            if (!Schema::hasColumn('student_profiles', 'mother_mobile_number')) {
                $table->string('mother_mobile_number', 20)->nullable()->after('mother_email');
            }
        });

        StudentProfile::query()->whereNull('user_id')->chunkById(500, function ($profiles) {
            $studentUserMap = Student::query()
                ->whereIn('id', $profiles->pluck('student_id')->filter()->all())
                ->pluck('user_id', 'id');

            foreach ($profiles as $profile) {
                $userId = $studentUserMap->get($profile->student_id);
                if ($userId) {
                    $profile->updateQuietly(['user_id' => $userId]);
                }
            }
        });

        if (Schema::hasColumn('student_profiles', 'father_mobile')) {
            StudentProfile::query()
                ->whereNull('father_mobile_number')
                ->whereNotNull('father_mobile')
                ->update(['father_mobile_number' => \DB::raw('father_mobile')]);
        }

        if (Schema::hasColumn('student_profiles', 'mother_mobile')) {
            StudentProfile::query()
                ->whereNull('mother_mobile_number')
                ->whereNotNull('mother_mobile')
                ->update(['mother_mobile_number' => \DB::raw('mother_mobile')]);
        }
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('student_profiles', 'father_mobile_number')) {
                $table->dropColumn('father_mobile_number');
            }

            if (Schema::hasColumn('student_profiles', 'mother_mobile_number')) {
                $table->dropColumn('mother_mobile_number');
            }

            if (Schema::hasColumn('student_profiles', 'user_id')) {
                $table->dropConstrainedForeignId('user_id');
            }
        });
    }
};
