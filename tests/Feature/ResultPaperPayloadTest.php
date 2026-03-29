<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassModel;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\ResultMarkSnapshot;
use App\Models\ResultVisibilityControl;
use App\Models\SchoolSetting;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentProfile;
use App\Models\StudentResult;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ResultPaperPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_result_paper_includes_backend_school_details_and_normalized_asset_urls(): void
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

        Sanctum::actingAs($admin);

        Storage::disk('public')->put(
            'students/profile-avatar.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aP1cAAAAASUVORK5CYII=')
        );
        Storage::disk('public')->put(
            'students/avatars/uq3WKpY7czm88YHNAkMHhjOWeecHn9FEhok5Qi3H.jpg',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aP1cAAAAASUVORK5CYII=')
        );

        SchoolSetting::putValue('school_name', 'Backend Public School');
        SchoolSetting::putValue('school_address', 'Main Road, Motihari');
        SchoolSetting::putValue('school_phone', '0612-123456');
        SchoolSetting::putValue('school_mobile_number_1', '9999999999');
        SchoolSetting::putValue('school_website', 'https://school.test');
        SchoolSetting::putValue('school_registration_number', 'REG-2026');
        SchoolSetting::putValue('school_udise_code', 'UDISE-42');
        SchoolSetting::putValue('school_logo_url', 'storage/assets/ips.png');
        SchoolSetting::putValue('school_watermark_logo_url', 'storage/assets/ips.png');

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
            'avatar_url' => 'students/profile-photo.png',
            'status' => 'active',
        ]);

        StudentProfile::create([
            'student_id' => $student->id,
            'user_id' => $studentUser->id,
            'avatar_url' => 'students/profile-avatar.png',
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
            'school_snapshot' => [
                'name' => 'Backend Public School',
                'address' => 'Main Road, Motihari',
                'phone' => '0612-123456',
                'mobile_number_1' => '9999999999',
                'website' => 'https://school.test',
                'registration_number' => 'REG-2026',
                'udise_code' => 'UDISE-42',
                'logo_url' => 'storage/assets/ips.png',
                'watermark_logo_url' => 'storage/assets/ips.png',
                'watermark_text' => 'Backend Public School',
            ],
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
            'visibility_status' => 'visible',
            'visibility_version' => 1,
        ]);

        DB::table('class_subjects')
            ->where('class_id', $class->id)
            ->where('subject_id', $subject->id)
            ->update(['pass_marks' => 99]);
        SchoolSetting::putValue('school_name', 'Changed Future School');

        $response = $this->getJson("/api/v1/results/{$result->id}/paper");

        $response->assertOk();
        $response->assertJsonPath('school.name', 'Backend Public School');
        $response->assertJsonPath('school.address', 'Main Road, Motihari');
        $response->assertJsonPath('school.registration_number', 'REG-2026');
        $response->assertJsonPath('school.udise_code', 'UDISE-42');
        $response->assertJsonPath('school.logo_url', url('storage/assets/ips.png'));
        $response->assertJsonPath('school.watermark_logo_url', url('storage/assets/ips.png'));
        $this->assertStringStartsWith('data:image/', (string) data_get($response->json(), 'school.logo_data_url'));
        $this->assertStringStartsWith('data:image/', (string) data_get($response->json(), 'school.watermark_logo_data_url'));
        $response->assertJsonPath('result_paper.photo_url', url('storage/students/profile-avatar.png'));
        $this->assertStringStartsWith('data:image/', (string) data_get($response->json(), 'result_paper.photo_data_url'));
        $response->assertJsonPath('result_paper.roll_number', 12);
        $response->assertJsonPath('result_paper.rank', 1);
        $this->assertSame(35.0, (float) data_get($response->json(), 'result_paper.total_passing_marks'));
        $this->assertSame(35.0, (float) data_get($response->json(), 'result_paper.subjects.0.passing_marks'));
        $this->assertSame(100.0, (float) data_get($response->json(), 'result_paper.subjects.0.max_marks'));
        $this->assertSame(88.0, (float) data_get($response->json(), 'result_paper.subjects.0.obtained_marks'));
    }

    public function test_result_paper_computes_missing_subject_grade_from_snapshot_marks(): void
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

        Sanctum::actingAs($admin);

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
            'admission_number' => 'ADM-102',
            'admission_date' => now()->toDateString(),
            'date_of_birth' => '2012-01-15',
            'gender' => 'male',
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
            'roll_number' => 13,
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
            'name' => 'Science',
            'code' => 'SCI-101',
            'subject_code' => 'SCI',
            'type' => 'core',
            'status' => 'active',
        ]);

        StudentResult::create([
            'exam_session_id' => $session->id,
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'total_marks' => 90,
            'total_max_marks' => 100,
            'percentage' => 90,
            'grade' => 'A1',
            'rank' => 1,
            'result_status' => 'pass',
            'version' => 2,
            'is_superseded' => true,
            'published_by' => $admin->id,
            'published_at' => now(),
            'verification_uuid' => '123e4567-e89b-12d3-a456-426614174111',
            'verification_hash' => hash_hmac('sha256', '123e4567-e89b-12d3-a456-426614174111', config('app.key')),
            'verification_status' => 'active',
        ]);

        $result = StudentResult::create([
            'exam_session_id' => $session->id,
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'total_marks' => 76,
            'total_max_marks' => 100,
            'percentage' => 76,
            'grade' => 'A',
            'rank' => null,
            'result_status' => 'pass',
            'version' => 1,
            'is_superseded' => false,
            'published_by' => $admin->id,
            'published_at' => now(),
            'verification_uuid' => '223e4567-e89b-12d3-a456-426614174000',
            'verification_hash' => hash_hmac('sha256', '223e4567-e89b-12d3-a456-426614174000', config('app.key')),
            'verification_status' => 'active',
        ]);

        ResultMarkSnapshot::create([
            'exam_session_id' => $session->id,
            'student_result_id' => $result->id,
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'obtained_marks' => 76,
            'max_marks' => 100,
            'passing_marks' => 35,
            'subject_name_snapshot' => 'Science',
            'subject_code_snapshot' => 'SCI',
            'grade' => null,
            'snapshot_version' => 1,
            'created_at' => now(),
        ]);

        ResultVisibilityControl::create([
            'student_result_id' => $result->id,
            'visibility_status' => 'visible',
            'visibility_version' => 1,
        ]);

        DB::table('grading_schemes')->insert([
            [
                'grade' => 'B1',
                'grade_point' => 8,
                'min_percentage' => 71,
                'max_percentage' => 80,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->getJson("/api/v1/results/{$result->id}/paper");

        $response->assertOk();
        $response->assertJsonPath('result_paper.roll_number', 13);
        $response->assertJsonPath('result_paper.rank', 1);
        $response->assertJsonPath('result_paper.subjects.0.grade', 'B1');
    }
}
