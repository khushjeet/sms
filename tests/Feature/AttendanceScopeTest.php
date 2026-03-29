<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\ClassModel;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_load_attendance_for_a_class_without_selecting_section(): void
    {
        $actor = $this->makeSuperAdmin();
        Sanctum::actingAs($actor);

        [$class, $sectionA, $sectionB] = $this->createClassWithSections();
        $otherClass = ClassModel::create([
            'name' => 'Class VIII',
            'numeric_order' => 8,
            'status' => 'active',
        ]);

        $year = AcademicYear::query()->firstOrFail();

        $alpha = $this->createEnrollment('ADM-101', 'Alpha', 'One', $year, $class, $sectionA, 1);
        $beta = $this->createEnrollment('ADM-102', 'Beta', 'Two', $year, $class, $sectionB, 2);
        $gamma = $this->createEnrollment('ADM-103', 'Gamma', 'Three', $year, $otherClass, null, 3);

        Attendance::create([
            'enrollment_id' => $alpha->id,
            'date' => '2026-03-21',
            'status' => 'present',
            'marked_by' => $actor->id,
            'marked_at' => now(),
            'is_locked' => false,
        ]);

        Attendance::create([
            'enrollment_id' => $gamma->id,
            'date' => '2026-03-21',
            'status' => 'absent',
            'marked_by' => $actor->id,
            'marked_at' => now(),
            'is_locked' => false,
        ]);

        $response = $this->getJson('/api/v1/attendance/section?' . http_build_query([
            'class_id' => $class->id,
            'date' => '2026-03-21',
        ]));

        $response->assertOk();
        $this->assertCount(2, $response->json());
        $actualIds = array_column($response->json(), 'enrollment_id');
        sort($actualIds);
        $expectedIds = [$alpha->id, $beta->id];
        sort($expectedIds);
        $this->assertSame($expectedIds, $actualIds);
    }

    public function test_super_admin_can_mark_attendance_for_all_sections_in_a_class(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin());

        [$class, $sectionA, $sectionB] = $this->createClassWithSections();
        $otherClass = ClassModel::create([
            'name' => 'Class IX',
            'numeric_order' => 9,
            'status' => 'active',
        ]);

        $year = AcademicYear::query()->firstOrFail();

        $alpha = $this->createEnrollment('ADM-201', 'Alpha', 'One', $year, $class, $sectionA, 1);
        $beta = $this->createEnrollment('ADM-202', 'Beta', 'Two', $year, $class, $sectionB, 2);
        $gamma = $this->createEnrollment('ADM-203', 'Gamma', 'Three', $year, $otherClass, null, 3);

        $this->postJson('/api/v1/attendance/mark', [
            'class_id' => $class->id,
            'date' => '2026-03-22',
            'attendances' => [
                [
                    'enrollment_id' => $alpha->id,
                    'status' => 'present',
                ],
                [
                    'enrollment_id' => $beta->id,
                    'status' => 'absent',
                    'remarks' => 'Sick leave submitted',
                ],
                [
                    'enrollment_id' => $gamma->id,
                    'status' => 'leave',
                ],
            ],
        ])->assertOk();

        $this->assertTrue(
            Attendance::query()
                ->where('enrollment_id', $alpha->id)
                ->whereDate('date', '2026-03-22')
                ->where('status', 'present')
                ->exists()
        );

        $this->assertTrue(
            Attendance::query()
                ->where('enrollment_id', $beta->id)
                ->whereDate('date', '2026-03-22')
                ->where('status', 'absent')
                ->where('remarks', 'Sick leave submitted')
                ->exists()
        );

        $this->assertFalse(
            Attendance::query()
                ->where('enrollment_id', $gamma->id)
                ->whereDate('date', '2026-03-22')
                ->exists()
        );
    }

    private function createClassWithSections(): array
    {
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

        return [$class, $sectionA, $sectionB];
    }

    private function createEnrollment(
        string $admissionNumber,
        string $firstName,
        string $lastName,
        AcademicYear $year,
        ClassModel $class,
        ?Section $section,
        int $rollNumber
    ): Enrollment {
        $user = User::create([
            'email' => strtolower($admissionNumber) . '@school.test',
            'password' => 'password',
            'role' => 'student',
            'first_name' => $firstName,
            'last_name' => $lastName,
            'status' => 'active',
        ]);

        $student = Student::create([
            'user_id' => $user->id,
            'admission_number' => $admissionNumber,
            'admission_date' => '2026-04-01',
            'date_of_birth' => '2014-01-01',
            'gender' => 'male',
            'status' => 'active',
        ]);

        return Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'class_id' => $class->id,
            'section_id' => $section?->id,
            'roll_number' => $rollNumber,
            'enrollment_date' => '2026-04-01',
            'status' => 'active',
            'is_locked' => false,
        ]);
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
