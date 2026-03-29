<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassModel;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentListFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_students_list_can_be_filtered_by_section(): void
    {
        Sanctum::actingAs($this->makeUser('super_admin'));

        $year = AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'active',
            'is_current' => true,
        ]);

        $class = ClassModel::create([
            'name' => 'Class 8',
            'numeric_order' => 8,
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

        $studentInA = $this->makeStudent('A-001');
        $studentInB = $this->makeStudent('B-001');

        Enrollment::create([
            'student_id' => $studentInA->id,
            'academic_year_id' => $year->id,
            'class_id' => $class->id,
            'section_id' => $sectionA->id,
            'roll_number' => 1,
            'enrollment_date' => '2026-04-05',
            'status' => 'active',
            'is_locked' => false,
        ]);

        Enrollment::create([
            'student_id' => $studentInB->id,
            'academic_year_id' => $year->id,
            'class_id' => $class->id,
            'section_id' => $sectionB->id,
            'roll_number' => 2,
            'enrollment_date' => '2026-04-05',
            'status' => 'active',
            'is_locked' => false,
        ]);

        $this->getJson('/api/v1/students?section_id=' . $sectionA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $studentInA->id);
    }

    public function test_students_list_includes_registered_students_without_current_enrollment(): void
    {
        Sanctum::actingAs($this->makeUser('super_admin'));

        $year = AcademicYear::create([
            'name' => '2025-2026',
            'start_date' => '2025-04-01',
            'end_date' => '2026-03-31',
            'status' => 'closed',
            'is_current' => false,
        ]);

        $class = ClassModel::create([
            'name' => 'Class 7',
            'numeric_order' => 7,
            'status' => 'active',
        ]);

        $section = Section::create([
            'class_id' => $class->id,
            'academic_year_id' => $year->id,
            'name' => 'C',
            'capacity' => 35,
            'status' => 'active',
        ]);

        $registeredStudent = $this->makeStudent('REG-001');

        Enrollment::create([
            'student_id' => $registeredStudent->id,
            'academic_year_id' => $year->id,
            'class_id' => $class->id,
            'section_id' => $section->id,
            'roll_number' => 7,
            'enrollment_date' => '2025-04-10',
            'status' => 'active',
            'is_locked' => false,
        ]);

        $response = $this->getJson('/api/v1/students')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $registeredStudent->id,
                'admission_number' => 'REG-001',
            ]);

        $studentRow = collect($response->json('data'))->firstWhere('id', $registeredStudent->id);

        $this->assertNotNull($studentRow);
        $this->assertSame($section->id, $studentRow['latest_enrollment']['section_id'] ?? null);
    }

    public function test_students_list_class_filter_includes_registered_students_from_profile_class(): void
    {
        Sanctum::actingAs($this->makeUser('super_admin'));

        $class = ClassModel::create([
            'name' => 'Class 6',
            'numeric_order' => 6,
            'status' => 'active',
        ]);

        $otherClass = ClassModel::create([
            'name' => 'Class 9',
            'numeric_order' => 9,
            'status' => 'active',
        ]);

        $registeredStudent = $this->makeStudent('REG-CLASS');
        $otherStudent = $this->makeStudent('REG-OTHER');

        StudentProfile::create([
            'student_id' => $registeredStudent->id,
            'user_id' => $registeredStudent->user_id,
            'class_id' => $class->id,
            'roll_number' => '11',
        ]);

        StudentProfile::create([
            'student_id' => $otherStudent->id,
            'user_id' => $otherStudent->user_id,
            'class_id' => $otherClass->id,
            'roll_number' => '22',
        ]);

        $this->getJson('/api/v1/students?class_id=' . $class->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $registeredStudent->id);
    }

    private function makeUser(string $role): User
    {
        return User::create([
            'email' => uniqid($role . '-', true) . '@school.test',
            'password' => 'password123',
            'role' => $role,
            'first_name' => ucfirst($role),
            'last_name' => 'User',
            'status' => 'active',
        ]);
    }

    private function makeStudent(string $admissionNumber): Student
    {
        $user = $this->makeUser('student');

        return Student::create([
            'user_id' => $user->id,
            'admission_number' => $admissionNumber,
            'admission_date' => '2026-04-01',
            'date_of_birth' => '2012-01-01',
            'gender' => 'male',
            'status' => 'active',
        ]);
    }
}
