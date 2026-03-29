<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassModel;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\ParentModel;
use App\Models\ResultMarkSnapshot;
use App\Models\ResultVisibilityControl;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentProfile;
use App\Models\StudentResult;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentResultAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_view_own_published_result_paper(): void
    {
        $context = $this->createPublishedResultFixture();

        Sanctum::actingAs($context['student_user']);

        $response = $this->getJson("/api/v1/results/{$context['result']->id}/paper");

        $response->assertOk();
        $response->assertJsonPath('result_paper.student_result_id', $context['result']->id);
        $response->assertJsonPath('result_paper.student_name', 'Aman Kumar');
        $response->assertJsonPath('result_paper.exam_name', 'Final Exam');
    }

    public function test_student_cannot_view_another_students_published_result_paper(): void
    {
        $context = $this->createPublishedResultFixture();

        $otherStudentUser = User::create([
            'email' => 'other-student+' . uniqid() . '@school.test',
            'password' => 'password',
            'role' => 'student',
            'first_name' => 'Other',
            'last_name' => 'Student',
            'status' => 'active',
        ]);

        $otherStudent = Student::create([
            'user_id' => $otherStudentUser->id,
            'admission_number' => 'ADM-202',
            'admission_date' => now()->toDateString(),
            'date_of_birth' => '2012-01-15',
            'gender' => 'male',
            'status' => 'active',
        ]);

        StudentProfile::create([
            'student_id' => $otherStudent->id,
            'user_id' => $otherStudentUser->id,
            'father_name' => 'Another Parent',
        ]);

        Sanctum::actingAs($otherStudentUser);

        $response = $this->getJson("/api/v1/results/{$context['result']->id}/paper");

        $response->assertForbidden();
        $response->assertJsonPath('message', 'You can only view allowed student results.');
    }

    public function test_parent_can_list_and_view_linked_students_published_results(): void
    {
        $context = $this->createPublishedResultFixture();

        $parentUser = User::create([
            'email' => 'parent+' . uniqid() . '@school.test',
            'password' => 'password',
            'role' => 'parent',
            'first_name' => 'Priya',
            'last_name' => 'Kumar',
            'status' => 'active',
        ]);

        $parent = ParentModel::create([
            'user_id' => $parentUser->id,
        ]);

        $student = Student::query()->findOrFail($context['result']->student_id);
        $student->parents()->attach($parent->id, [
            'relation' => 'mother',
            'is_primary' => true,
        ]);

        Sanctum::actingAs($parentUser);

        $listResponse = $this->getJson('/api/v1/results/published');
        $listResponse->assertOk();
        $listResponse->assertJsonCount(1, 'data');
        $listResponse->assertJsonPath('data.0.id', $context['result']->id);

        $paperResponse = $this->getJson("/api/v1/results/{$context['result']->id}/paper");
        $paperResponse->assertOk();
        $paperResponse->assertJsonPath('result_paper.student_result_id', $context['result']->id);
    }

    public function test_student_list_shows_hidden_result_notice_when_super_admin_hides_result(): void
    {
        $context = $this->createPublishedResultFixture('withheld');

        Sanctum::actingAs($context['student_user']);

        $response = $this->getJson('/api/v1/results/published');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
        $response->assertJsonPath('hidden_result_notice', 'Result is not available for you currently. Please contact administration.');
    }

    public function test_parent_list_shows_hidden_result_notice_when_super_admin_hides_result(): void
    {
        $context = $this->createPublishedResultFixture('withheld');

        $parentUser = User::create([
            'email' => 'parent+' . uniqid() . '@school.test',
            'password' => 'password',
            'role' => 'parent',
            'first_name' => 'Priya',
            'last_name' => 'Kumar',
            'status' => 'active',
        ]);

        $parent = ParentModel::create([
            'user_id' => $parentUser->id,
        ]);

        $student = Student::query()->findOrFail($context['result']->student_id);
        $student->parents()->attach($parent->id, [
            'relation' => 'mother',
            'is_primary' => true,
        ]);

        Sanctum::actingAs($parentUser);

        $response = $this->getJson('/api/v1/results/published');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
        $response->assertJsonPath('hidden_result_notice', 'Result is not available for you currently. Please contact administration.');
    }

    /**
     * @return array{student_user: User, result: StudentResult}
     */
    private function createPublishedResultFixture(string $visibilityStatus = 'visible'): array
    {
        $this->seed(RbacSeeder::class);

        $admin = User::create([
            'email' => 'superadmin+' . uniqid() . '@school.test',
            'password' => 'password',
            'role' => 'super_admin',
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'status' => 'active',
        ]);

        $studentUser = User::create([
            'email' => 'student+' . uniqid() . '@school.test',
            'password' => 'password',
            'role' => 'student',
            'first_name' => 'Aman',
            'last_name' => 'Kumar',
            'status' => 'active',
        ]);

        $student = Student::create([
            'user_id' => $studentUser->id,
            'admission_number' => 'ADM-101',
            'admission_date' => now()->toDateString(),
            'date_of_birth' => '2012-01-15',
            'gender' => 'male',
            'address' => 'Naugawa',
            'city' => 'Yogapatti',
            'state' => 'Bihar',
            'pincode' => '845452',
            'status' => 'active',
        ]);

        StudentProfile::create([
            'student_id' => $student->id,
            'user_id' => $studentUser->id,
            'father_name' => 'Ramesh Kumar',
        ]);

        $academicYear = AcademicYear::create([
            'name' => '2025-2026',
            'start_date' => '2025-04-01',
            'end_date' => '2026-03-31',
            'status' => 'active',
            'is_current' => true,
        ]);

        $class = ClassModel::create([
            'name' => 'Class 10',
            'numeric_order' => 10,
            'status' => 'active',
        ]);

        $section = Section::create([
            'class_id' => $class->id,
            'academic_year_id' => $academicYear->id,
            'name' => 'A',
            'capacity' => 40,
            'status' => 'active',
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $academicYear->id,
            'class_id' => $class->id,
            'section_id' => $section->id,
            'roll_number' => 12,
            'enrollment_date' => now()->toDateString(),
            'status' => 'active',
            'is_locked' => false,
        ]);

        $session = ExamSession::create([
            'academic_year_id' => $academicYear->id,
            'class_id' => $class->id,
            'name' => 'Final Exam',
            'class_name_snapshot' => 'Class 10',
            'exam_name_snapshot' => 'Final Exam',
            'academic_year_label_snapshot' => '2025-2026',
            'school_snapshot' => ['name' => 'Backend Public School'],
            'identity_locked_at' => now(),
            'status' => 'published',
            'published_at' => now(),
        ]);

        $subject = Subject::create([
            'name' => 'Mathematics',
            'code' => 'MATH-101',
            'subject_code' => 'MATH',
            'type' => 'core',
            'status' => 'active',
        ]);

        DB::table('class_subjects')->insert([
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $academicYear->id,
            'max_marks' => 100,
            'pass_marks' => 35,
            'is_mandatory' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = StudentResult::create([
            'exam_session_id' => $session->id,
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'total_marks' => 88,
            'total_max_marks' => 100,
            'percentage' => 88,
            'grade' => 'A',
            'rank' => 1,
            'result_status' => 'pass',
            'version' => 1,
            'is_superseded' => false,
            'published_by' => $admin->id,
            'published_at' => now(),
            'verification_uuid' => '123e4567-e89b-12d3-a456-426614174000',
            'verification_hash' => hash_hmac('sha256', '123e4567-e89b-12d3-a456-426614174000', config('app.key')),
            'verification_status' => 'active',
        ]);

        ResultMarkSnapshot::create([
            'exam_session_id' => $session->id,
            'student_result_id' => $result->id,
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'obtained_marks' => 88,
            'max_marks' => 100,
            'passing_marks' => 35,
            'subject_name_snapshot' => 'Mathematics',
            'subject_code_snapshot' => 'MATH',
            'grade' => 'A',
            'snapshot_version' => 1,
            'created_at' => now(),
        ]);

        ResultVisibilityControl::create([
            'student_result_id' => $result->id,
            'visibility_status' => $visibilityStatus,
            'visibility_version' => 1,
        ]);

        return [
            'student_user' => $studentUser,
            'result' => $result,
        ];
    }
}
