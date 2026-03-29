<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassModel;
use App\Models\Enrollment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TimetableManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_save_and_load_section_timetable_without_affecting_other_sections(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin());

        $year = AcademicYear::create([
            'name' => 'AY 2026',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'active',
            'is_current' => true,
        ]);

        $class = ClassModel::create([
            'name' => 'Class XI',
            'numeric_order' => 11,
            'status' => 'active',
        ]);

        $sectionA = Section::create([
            'class_id' => $class->id,
            'academic_year_id' => $year->id,
            'name' => 'A',
            'capacity' => 40,
            'status' => 'active',
        ]);

        $sectionB = Section::create([
            'class_id' => $class->id,
            'academic_year_id' => $year->id,
            'name' => 'B',
            'capacity' => 40,
            'status' => 'active',
        ]);

        $slot = TimeSlot::create([
            'name' => 'Period 1',
            'start_time' => '08:00',
            'end_time' => '08:45',
            'is_break' => false,
            'slot_order' => 1,
        ]);

        $subject = Subject::create([
            'name' => 'Mathematics',
            'code' => 'MATH_101',
            'subject_code' => 'MATH_101',
            'type' => 'core',
            'status' => 'active',
            'is_active' => true,
        ]);

        DB::table('class_subjects')->insert([
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $year->id,
            'academic_year_exam_config_id' => null,
            'max_marks' => 100,
            'pass_marks' => 35,
            'is_mandatory' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $teacher = User::create([
            'email' => 'teacher@example.test',
            'password' => 'password',
            'role' => 'teacher',
            'first_name' => 'Class',
            'last_name' => 'Teacher',
            'status' => 'active',
        ]);

        $this->postJson('/api/v1/timetable/section', [
            'academic_year_id' => $year->id,
            'section_id' => $sectionA->id,
            'entries' => [
                [
                    'day_of_week' => 'monday',
                    'time_slot_id' => $slot->id,
                    'subject_id' => $subject->id,
                    'teacher_id' => $teacher->id,
                    'room_number' => 'R-101',
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('timetables', [
            'academic_year_id' => $year->id,
            'section_id' => $sectionA->id,
            'day_of_week' => 'monday',
            'time_slot_id' => $slot->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'room_number' => 'R-101',
        ]);

        $this->assertDatabaseMissing('timetables', [
            'academic_year_id' => $year->id,
            'section_id' => $sectionB->id,
            'day_of_week' => 'monday',
            'time_slot_id' => $slot->id,
        ]);

        $this->getJson('/api/v1/timetable/section?' . http_build_query([
            'academic_year_id' => $year->id,
            'section_id' => $sectionA->id,
        ]))
            ->assertOk()
            ->assertJsonPath('meta.section_id', $sectionA->id)
            ->assertJsonPath('rows.0.subject_id', $subject->id)
            ->assertJsonPath('rows.0.teacher_id', $teacher->id)
            ->assertJsonPath('rows.0.room_number', 'R-101');
    }

    public function test_student_can_download_own_timetable_pdf(): void
    {
        [$year, $class, $section, $slot, $teacher] = $this->seedTimetableBase();

        $subject = Subject::create([
            'name' => 'Science',
            'code' => 'SCI_101',
            'subject_code' => 'SCI_101',
            'type' => 'core',
            'status' => 'active',
            'is_active' => true,
        ]);

        DB::table('class_subjects')->insert([
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $year->id,
            'academic_year_exam_config_id' => null,
            'max_marks' => 100,
            'pass_marks' => 35,
            'is_mandatory' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('timetables')->insert([
            'section_id' => $section->id,
            'academic_year_id' => $year->id,
            'day_of_week' => 'monday',
            'time_slot_id' => $slot->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'room_number' => 'Lab-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentUser = User::create([
            'email' => 'student+' . uniqid() . '@school.test',
            'password' => 'password',
            'role' => 'student',
            'first_name' => 'Demo',
            'last_name' => 'Student',
            'status' => 'active',
        ]);

        $student = Student::create([
            'user_id' => $studentUser->id,
            'admission_number' => 'ADM-500',
            'admission_date' => now()->toDateString(),
            'date_of_birth' => '2012-01-15',
            'gender' => 'male',
            'status' => 'active',
        ]);

        Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'class_id' => $class->id,
            'section_id' => $section->id,
            'roll_number' => '14',
            'status' => 'active',
            'enrollment_date' => now()->toDateString(),
        ]);

        $this->grantRolePermissions($studentUser, 'student', [
            'student.view_timetable',
        ]);

        Sanctum::actingAs($studentUser);

        $this->get('/api/v1/timetable/student/me/download?academic_year_id=' . $year->id)
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_teacher_can_view_assigned_timetable(): void
    {
        [$year, $class, $section, $slot, $teacher] = $this->seedTimetableBase();

        $subject = Subject::create([
            'name' => 'English',
            'code' => 'ENG_101',
            'subject_code' => 'ENG_101',
            'type' => 'core',
            'status' => 'active',
            'is_active' => true,
        ]);

        DB::table('timetables')->insert([
            'section_id' => $section->id,
            'academic_year_id' => $year->id,
            'day_of_week' => 'tuesday',
            'time_slot_id' => $slot->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'room_number' => 'R-202',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($teacher);

        $this->getJson('/api/v1/teacher-academics/timetable?academic_year_id=' . $year->id)
            ->assertOk()
            ->assertJsonPath('meta.teacher_id', $teacher->id)
            ->assertJsonPath('rows.0.subject_name', 'English')
            ->assertJsonPath('rows.0.class_name', 'Class XI')
            ->assertJsonPath('rows.0.section_name', 'A');
    }

    private function seedTimetableBase(): array
    {
        $year = AcademicYear::create([
            'name' => 'AY 2026',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'active',
            'is_current' => true,
        ]);

        $class = ClassModel::create([
            'name' => 'Class XI',
            'numeric_order' => 11,
            'status' => 'active',
        ]);

        $section = Section::create([
            'class_id' => $class->id,
            'academic_year_id' => $year->id,
            'name' => 'A',
            'capacity' => 40,
            'status' => 'active',
        ]);

        $slot = TimeSlot::create([
            'name' => 'Period 1',
            'start_time' => '08:00',
            'end_time' => '08:45',
            'is_break' => false,
            'slot_order' => 1,
        ]);

        $teacher = User::create([
            'email' => 'teacher+' . uniqid() . '@school.test',
            'password' => 'password',
            'role' => 'teacher',
            'first_name' => 'Class',
            'last_name' => 'Teacher',
            'status' => 'active',
        ]);

        return [$year, $class, $section, $slot, $teacher];
    }

    private function grantRolePermissions(User $user, string $roleName, array $permissionCodes): void
    {
        $role = Role::firstOrCreate([
            'name' => $roleName,
        ], [
            'description' => ucfirst(str_replace('_', ' ', $roleName)),
            'is_system_role' => true,
        ]);

        foreach ($permissionCodes as $code) {
            $permission = Permission::firstOrCreate([
                'code' => $code,
            ], [
                'module' => explode('.', $code)[0] ?? 'system',
                'action' => str_replace('.', '_', $code),
            ]);

            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->assignRole($roleName);
    }

    private function makeSuperAdmin(): User
    {
        return User::create([
            'email' => 'superadmin+' . uniqid() . '@school.test',
            'password' => 'password',
            'role' => 'super_admin',
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'status' => 'active',
        ]);
    }
}
