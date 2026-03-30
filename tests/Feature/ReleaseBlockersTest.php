<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AcademicYearExamConfig;
use App\Models\ClassModel;
use App\Models\CompiledMark;
use App\Models\Enrollment;
use App\Models\SchoolSetting;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReleaseBlockersTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_super_admin_cannot_manage_school_signatures(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->makeUser('student'));

        $this->getJson('/api/v1/school/signatures')->assertForbidden();

        $this->postJson('/api/v1/school/signatures', [
            'principal_signature' => UploadedFile::fake()->image('principal.png'),
        ])->assertForbidden();

        $this->deleteJson('/api/v1/school/signatures/principal')->assertForbidden();
    }

    public function test_class_wide_compile_rejects_already_finalized_rows_across_sections(): void
    {
        $admin = $this->makeUser('super_admin');
        Sanctum::actingAs($admin);

        $academicYear = AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'active',
            'is_current' => true,
        ]);

        $class = ClassModel::create([
            'name' => 'Class 10',
            'numeric_order' => 10,
            'status' => 'active',
        ]);

        $sectionA = Section::create([
            'class_id' => $class->id,
            'academic_year_id' => $academicYear->id,
            'name' => 'A',
            'capacity' => 40,
            'status' => 'active',
        ]);

        Section::create([
            'class_id' => $class->id,
            'academic_year_id' => $academicYear->id,
            'name' => 'B',
            'capacity' => 40,
            'status' => 'active',
        ]);

        $studentUser = $this->makeUser('student', 'student1');
        $student = Student::create([
            'user_id' => $studentUser->id,
            'admission_number' => 'ADM-500',
            'admission_date' => '2026-04-02',
            'date_of_birth' => '2011-01-15',
            'gender' => 'male',
            'status' => 'active',
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $academicYear->id,
            'class_id' => $class->id,
            'section_id' => $sectionA->id,
            'roll_number' => 1,
            'enrollment_date' => '2026-04-02',
            'status' => 'active',
            'is_locked' => false,
        ]);

        $subject = Subject::create([
            'name' => 'Mathematics',
            'code' => 'MATH-10',
            'type' => 'core',
            'status' => 'active',
        ]);

        $examConfig = AcademicYearExamConfig::create([
            'academic_year_id' => $academicYear->id,
            'name' => 'Mid Term',
            'sequence' => 1,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        DB::table('class_subjects')->insert([
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $academicYear->id,
            'academic_year_exam_config_id' => $examConfig->id,
            'max_marks' => 100,
            'pass_marks' => 35,
            'is_mandatory' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        CompiledMark::create([
            'enrollment_id' => $enrollment->id,
            'subject_id' => $subject->id,
            'section_id' => $sectionA->id,
            'academic_year_id' => $academicYear->id,
            'exam_configuration_id' => $examConfig->id,
            'marked_on' => '2026-04-14',
            'marks_obtained' => 75,
            'max_marks' => 100,
            'remarks' => 'Locked',
            'is_finalized' => true,
            'compiled_by' => $admin->id,
            'compiled_at' => now(),
            'finalized_by' => $admin->id,
            'finalized_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/admin-marks/compile', [
            'class_id' => $class->id,
            'academic_year_id' => $academicYear->id,
            'subject_id' => $subject->id,
            'exam_configuration_id' => $examConfig->id,
            'marked_on' => '2026-04-14',
            'rows' => [
                [
                    'enrollment_id' => $enrollment->id,
                    'marks_obtained' => 80,
                    'max_marks' => 100,
                    'remarks' => 'Updated',
                ],
            ],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Some rows are already finalized and cannot be modified.',
            ]);

        $compiledMark = CompiledMark::query()->firstOrFail();
        $this->assertTrue($compiledMark->is_finalized);
        $this->assertSame('75.00', $compiledMark->marks_obtained);
        $this->assertSame('Locked', $compiledMark->remarks);
    }

    public function test_admin_marks_filters_return_class_subjects_and_academic_year_exam_configs(): void
    {
        $admin = $this->makeUser('super_admin');
        Sanctum::actingAs($admin);

        $academicYear = AcademicYear::create([
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
            'academic_year_id' => $academicYear->id,
            'name' => 'A',
            'capacity' => 40,
            'status' => 'active',
        ]);

        Section::create([
            'class_id' => $class->id,
            'academic_year_id' => $academicYear->id,
            'name' => 'B',
            'capacity' => 40,
            'status' => 'active',
        ]);

        $examConfig = AcademicYearExamConfig::create([
            'academic_year_id' => $academicYear->id,
            'name' => 'Term 1',
            'sequence' => 1,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $math = Subject::create([
            'name' => 'Mathematics',
            'code' => 'MATH-8',
            'subject_code' => 'MATH',
            'type' => 'core',
            'status' => 'active',
        ]);

        Subject::create([
            'name' => 'Science',
            'code' => 'SCI-8',
            'subject_code' => 'SCI',
            'type' => 'core',
            'status' => 'active',
        ]);

        DB::table('class_subjects')->insert([
            'class_id' => $class->id,
            'subject_id' => $math->id,
            'academic_year_id' => $academicYear->id,
            'academic_year_exam_config_id' => $examConfig->id,
            'max_marks' => 100,
            'pass_marks' => 35,
            'is_mandatory' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/admin-marks/filters?class_id={$class->id}&academic_year_id={$academicYear->id}&section_id={$sectionA->id}");

        $response->assertOk();
        $response->assertJsonPath('academic_year.id', $academicYear->id);
        $response->assertJsonPath('academic_year.start_date', '2026-04-01');
        $response->assertJsonPath('sections.0.id', $sectionA->id);
        $response->assertJsonCount(2, 'sections');
        $response->assertJsonCount(1, 'subjects');
        $response->assertJsonPath('subjects.0.id', $math->id);
        $response->assertJsonPath('subjects.0.academic_year_exam_config_id', $examConfig->id);
        $response->assertJsonCount(1, 'exam_configurations');
        $response->assertJsonPath('exam_configurations.0.id', $examConfig->id);
    }

    public function test_admin_marks_sheet_rejects_date_outside_academic_year(): void
    {
        $admin = $this->makeUser('super_admin');
        Sanctum::actingAs($admin);

        $academicYear = AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'active',
            'is_current' => true,
        ]);

        $class = ClassModel::create([
            'name' => 'Class 9',
            'numeric_order' => 9,
            'status' => 'active',
        ]);

        Section::create([
            'class_id' => $class->id,
            'academic_year_id' => $academicYear->id,
            'name' => 'A',
            'capacity' => 40,
            'status' => 'active',
        ]);

        $subject = Subject::create([
            'name' => 'English',
            'code' => 'ENG-9',
            'subject_code' => 'ENG',
            'type' => 'core',
            'status' => 'active',
        ]);

        $examConfig = AcademicYearExamConfig::create([
            'academic_year_id' => $academicYear->id,
            'name' => 'Mid Term',
            'sequence' => 1,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        DB::table('class_subjects')->insert([
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $academicYear->id,
            'academic_year_exam_config_id' => $examConfig->id,
            'max_marks' => 100,
            'pass_marks' => 35,
            'is_mandatory' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/admin-marks/sheet?class_id={$class->id}&academic_year_id={$academicYear->id}&subject_id={$subject->id}&exam_configuration_id={$examConfig->id}&marked_on=2026-03-31");

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['marked_on']);
    }

    public function test_compiled_marks_create_append_only_history_versions(): void
    {
        $admin = $this->makeUser('super_admin');
        Sanctum::actingAs($admin);

        $academicYear = AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'active',
            'is_current' => true,
        ]);

        $class = ClassModel::create([
            'name' => 'Class 7',
            'numeric_order' => 7,
            'status' => 'active',
        ]);

        $section = Section::create([
            'class_id' => $class->id,
            'academic_year_id' => $academicYear->id,
            'name' => 'A',
            'capacity' => 40,
            'status' => 'active',
        ]);

        $studentUser = $this->makeUser('student', 'student-history');
        $student = Student::create([
            'user_id' => $studentUser->id,
            'admission_number' => 'ADM-HIS',
            'admission_date' => '2026-04-02',
            'date_of_birth' => '2012-01-15',
            'gender' => 'male',
            'status' => 'active',
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $academicYear->id,
            'class_id' => $class->id,
            'section_id' => $section->id,
            'roll_number' => 2,
            'enrollment_date' => '2026-04-02',
            'status' => 'active',
            'is_locked' => false,
        ]);

        $subject = Subject::create([
            'name' => 'Hindi',
            'code' => 'HIN-7',
            'subject_code' => 'HIN',
            'type' => 'core',
            'status' => 'active',
        ]);

        $examConfig = AcademicYearExamConfig::create([
            'academic_year_id' => $academicYear->id,
            'name' => 'Unit Test',
            'sequence' => 1,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        DB::table('class_subjects')->insert([
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $academicYear->id,
            'academic_year_exam_config_id' => $examConfig->id,
            'max_marks' => 100,
            'pass_marks' => 35,
            'is_mandatory' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'class_id' => $class->id,
            'academic_year_id' => $academicYear->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'exam_configuration_id' => $examConfig->id,
            'marked_on' => '2026-04-15',
            'rows' => [[
                'enrollment_id' => $enrollment->id,
                'marks_obtained' => 60,
                'max_marks' => 100,
                'remarks' => 'First save',
            ]],
        ];

        $this->postJson('/api/v1/admin-marks/compile', $payload)->assertOk();

        $payload['rows'][0]['marks_obtained'] = 68;
        $payload['rows'][0]['remarks'] = 'Updated save';
        $this->postJson('/api/v1/admin-marks/compile', $payload)->assertOk();

        $this->postJson('/api/v1/admin-marks/finalize', [
            'class_id' => $class->id,
            'academic_year_id' => $academicYear->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'exam_configuration_id' => $examConfig->id,
            'marked_on' => '2026-04-15',
        ])->assertOk();

        $compiledMark = \App\Models\CompiledMark::query()->firstOrFail();
        $histories = \App\Models\CompiledMarkHistory::query()
            ->where('compiled_mark_id', $compiledMark->id)
            ->orderBy('version_no')
            ->get();

        $this->assertCount(3, $histories);
        $this->assertSame('created', $histories[0]->action);
        $this->assertSame('updated', $histories[1]->action);
        $this->assertSame('finalized', $histories[2]->action);
    }

    public function test_admin_marks_filters_still_return_subjects_when_class_has_no_sections(): void
    {
        $admin = $this->makeUser('super_admin');
        Sanctum::actingAs($admin);

        $academicYear = AcademicYear::create([
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

        $subject = Subject::create([
            'name' => 'Science',
            'code' => 'SCI-8',
            'subject_code' => 'SCI',
            'type' => 'core',
            'status' => 'active',
        ]);

        $examConfig = AcademicYearExamConfig::create([
            'academic_year_id' => $academicYear->id,
            'name' => 'Mid Term',
            'sequence' => 1,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        DB::table('class_subjects')->insert([
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $academicYear->id,
            'academic_year_exam_config_id' => $examConfig->id,
            'max_marks' => 100,
            'pass_marks' => 35,
            'is_mandatory' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson("/api/v1/admin-marks/filters?class_id={$class->id}&academic_year_id={$academicYear->id}")
            ->assertOk()
            ->assertJsonFragment([
                'id' => $subject->id,
                'name' => 'Science',
            ])
            ->assertJsonFragment([
                'id' => $examConfig->id,
                'name' => 'Mid Term',
            ])
            ->assertJsonPath('sections', [])
            ->assertJsonPath('messages.sections', 'No sections are available for the selected class. Marks can still be assigned directly to enrollments without sections.');
    }

    public function test_compile_allows_saving_marks_when_enrollment_has_no_section(): void
    {
        $admin = $this->makeUser('super_admin');
        Sanctum::actingAs($admin);

        $academicYear = AcademicYear::create([
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

        $studentUser = $this->makeUser('student', 'student-no-section');
        $student = Student::create([
            'user_id' => $studentUser->id,
            'admission_number' => 'ADM-NOSEC',
            'admission_date' => '2026-04-02',
            'date_of_birth' => '2012-05-10',
            'gender' => 'male',
            'status' => 'active',
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $academicYear->id,
            'class_id' => $class->id,
            'section_id' => null,
            'roll_number' => 3,
            'enrollment_date' => '2026-04-02',
            'status' => 'active',
            'is_locked' => false,
        ]);

        $subject = Subject::create([
            'name' => 'Science',
            'code' => 'SCI-8',
            'subject_code' => 'SCI',
            'type' => 'core',
            'status' => 'active',
        ]);

        $examConfig = AcademicYearExamConfig::create([
            'academic_year_id' => $academicYear->id,
            'name' => 'Mid Term',
            'sequence' => 1,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        DB::table('class_subjects')->insert([
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $academicYear->id,
            'academic_year_exam_config_id' => $examConfig->id,
            'max_marks' => 100,
            'pass_marks' => 35,
            'is_mandatory' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/admin-marks/compile', [
            'class_id' => $class->id,
            'academic_year_id' => $academicYear->id,
            'subject_id' => $subject->id,
            'exam_configuration_id' => $examConfig->id,
            'marked_on' => '2026-04-15',
            'rows' => [[
                'enrollment_id' => $enrollment->id,
                'marks_obtained' => 45,
                'max_marks' => 50,
            ]],
        ])
            ->assertOk()
            ->assertJson([
                'message' => 'Compiled marks saved successfully.',
            ]);

        $compiledMark = CompiledMark::query()->firstOrFail();
        $this->assertSame($enrollment->id, $compiledMark->enrollment_id);
        $this->assertNull($compiledMark->section_id);
        $this->assertSame('45.00', $compiledMark->marks_obtained);
    }

    private function makeUser(string $role, string $prefix = 'user'): User
    {
        return User::create([
            'email' => $prefix . '+' . uniqid() . '@school.test',
            'password' => 'password',
            'role' => $role,
            'first_name' => ucfirst($prefix),
            'last_name' => ucfirst($role),
            'status' => 'active',
        ]);
    }
}
