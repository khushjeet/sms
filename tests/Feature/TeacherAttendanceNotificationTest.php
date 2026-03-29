<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassModel;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherAttendanceNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_attendance_save_creates_admin_notification(): void
    {
        $teacher = $this->makeUser('teacher');
        $superAdmin = $this->makeUser('super_admin');

        $year = AcademicYear::create([
            'name' => 'AY 2026',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'active',
            'is_current' => true,
        ]);

        $class = ClassModel::create([
            'name' => 'Class VII',
            'numeric_order' => 7,
            'status' => 'active',
        ]);

        $section = Section::create([
            'class_id' => $class->id,
            'academic_year_id' => $year->id,
            'name' => 'A',
            'capacity' => 40,
            'status' => 'active',
        ]);

        $studentUser = $this->makeUser('student');
        $student = Student::create([
            'user_id' => $studentUser->id,
            'admission_number' => 'ADM-777',
            'admission_date' => '2026-04-01',
            'date_of_birth' => '2014-01-01',
            'gender' => 'male',
            'status' => 'active',
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'class_id' => $class->id,
            'section_id' => $section->id,
            'roll_number' => 1,
            'enrollment_date' => '2026-04-01',
            'status' => 'active',
            'is_locked' => false,
        ]);

        $subjectId = \DB::table('subjects')->insertGetId([
            'name' => 'Mathematics',
            'subject_code' => 'MATH',
            'code' => 'MATH',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assignmentId = \DB::table('teacher_subject_assignments')->insertGetId([
            'teacher_id' => $teacher->id,
            'subject_id' => $subjectId,
            'class_id' => $class->id,
            'section_id' => $section->id,
            'academic_year_id' => $year->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($teacher);

        $this->postJson('/api/v1/teacher-academics/attendance', [
            'assignment_id' => $assignmentId,
            'date' => '2026-05-10',
            'attendances' => [
                [
                    'enrollment_id' => $enrollment->id,
                    'status' => 'present',
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $superAdmin->id,
            'type' => 'attendance',
            'title' => 'Student attendance marked',
        ]);
    }

    private function makeUser(string $role): User
    {
        return User::create([
            'email' => $role . '+' . uniqid() . '@school.test',
            'password' => 'password',
            'role' => $role,
            'first_name' => ucfirst($role),
            'last_name' => 'User',
            'status' => 'active',
        ]);
    }
}
