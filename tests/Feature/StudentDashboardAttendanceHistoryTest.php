<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\ClassModel;
use App\Models\Enrollment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentDashboardAttendanceHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_dashboard_attendance_history_falls_back_to_raw_attendance_when_monthly_summary_is_missing(): void
    {
        $year = AcademicYear::create([
            'name' => 'AY 2026',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'active',
            'is_current' => true,
        ]);

        $class = ClassModel::create([
            'name' => 'Class X',
            'numeric_order' => 10,
            'status' => 'active',
        ]);

        $section = Section::create([
            'class_id' => $class->id,
            'academic_year_id' => $year->id,
            'name' => 'A',
            'capacity' => 40,
            'status' => 'active',
        ]);

        $user = User::create([
            'email' => 'student@example.test',
            'password' => 'password',
            'role' => 'student',
            'first_name' => 'Portal',
            'last_name' => 'Student',
            'status' => 'active',
        ]);

        $studentRole = Role::firstOrCreate([
            'name' => 'student',
        ], [
            'description' => 'Student',
            'is_system_role' => true,
        ]);

        $permissionCodes = [
            'student.view_dashboard',
            'student.view_attendance',
            'student.view_attendance_history',
        ];

        foreach ($permissionCodes as $code) {
            $permission = Permission::firstOrCreate([
                'code' => $code,
            ], [
                'module' => 'student',
                'action' => str_replace('student.', '', $code),
            ]);

            $studentRole->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->assignRole('student');

        $student = Student::create([
            'user_id' => $user->id,
            'admission_number' => 'ADM-5001',
            'admission_date' => '2026-04-01',
            'date_of_birth' => '2011-01-01',
            'gender' => 'male',
            'status' => 'active',
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'class_id' => $class->id,
            'section_id' => $section->id,
            'roll_number' => 15,
            'enrollment_date' => '2026-04-01',
            'status' => 'active',
            'is_locked' => false,
        ]);

        Attendance::create([
            'enrollment_id' => $enrollment->id,
            'date' => '2026-05-01',
            'status' => 'present',
            'marked_by' => $user->id,
            'marked_at' => now(),
            'is_locked' => false,
        ]);

        Attendance::create([
            'enrollment_id' => $enrollment->id,
            'date' => '2026-05-02',
            'status' => 'half_day',
            'marked_by' => $user->id,
            'marked_at' => now(),
            'is_locked' => false,
        ]);

        Attendance::create([
            'enrollment_id' => $enrollment->id,
            'date' => '2026-05-03',
            'status' => 'absent',
            'marked_by' => $user->id,
            'marked_at' => now(),
            'is_locked' => false,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/dashboard/student?academic_year_id=' . $year->id . '&month=2026-05');

        $response->assertOk()
            ->assertJsonPath('attendance_overview.source', 'attendances')
            ->assertJsonPath('attendance_overview.total_present', 1)
            ->assertJsonPath('attendance_overview.total_half_day', 1)
            ->assertJsonPath('attendance_overview.total_absent', 1)
            ->assertJsonPath('attendance_history.source', 'attendances')
            ->assertJsonPath('attendance_history.items.0.month', '2026-05')
            ->assertJsonPath('attendance_history.items.0.present', 1)
            ->assertJsonPath('attendance_history.items.0.half_day', 1)
            ->assertJsonPath('attendance_history.items.0.absent', 1);
    }
}
