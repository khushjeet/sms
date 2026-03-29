<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AdmitCard;
use App\Models\AdmitScheduleSnapshot;
use App\Models\AdmitVisibilityControl;
use App\Models\ClassModel;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\SchoolSetting;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdmitCardDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_latest_admit_uses_pdf_download_route(): void
    {
        $this->seed(RbacSeeder::class);

        [$studentUser, $admitCard] = $this->createPublishedAdmitCard();

        Sanctum::actingAs($studentUser);

        $response = $this->getJson('/api/v1/admits/me');

        $response->assertOk();
        $response->assertJsonPath('state', 'published');
        $response->assertJsonPath('admit_card.id', $admitCard->id);
        $response->assertJsonPath('admit_card.download_url', "/api/v1/admits/{$admitCard->id}/paper/download");
    }

    public function test_admit_paper_returns_dynamic_school_details_from_settings(): void
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

        [, $admitCard] = $this->createPublishedAdmitCard();

        SchoolSetting::putValue('school_name', 'Dynamic Admit School');
        SchoolSetting::putValue('school_address', 'Main Road');
        SchoolSetting::putValue('school_phone', '9999999999');
        SchoolSetting::putValue('school_website', 'https://admit.test');
        SchoolSetting::putValue('school_registration_number', 'REG-ADMIT');
        SchoolSetting::putValue('school_udise_code', 'UDISE-ADMIT');
        SchoolSetting::putValue('school_logo_url', 'storage/assets/ips.png');
        SchoolSetting::putValue('school_watermark_logo_url', 'storage/assets/ips.png');

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/v1/admits/{$admitCard->id}/paper");

        $response->assertOk();
        $response->assertJsonPath('school.name', 'Dynamic Admit School');
        $response->assertJsonPath('school.address', 'Main Road');
        $response->assertJsonPath('school.phone', '9999999999');
        $response->assertJsonPath('school.website', 'https://admit.test');
        $response->assertJsonPath('school.registration_number', 'REG-ADMIT');
        $response->assertJsonPath('school.udise_code', 'UDISE-ADMIT');
        $this->assertStringStartsWith('http://', (string) data_get($response->json(), 'school.logo_url'));
        $this->assertStringStartsWith('data:image/', (string) data_get($response->json(), 'school.logo_data_url'));
        $this->assertStringStartsWith('data:image/', (string) data_get($response->json(), 'school.watermark_logo_data_url'));
    }

    public function test_admit_paper_prefers_student_profile_avatar_for_photo(): void
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

        Storage::disk('public')->put(
            'students/profile-avatar.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aP1cAAAAASUVORK5CYII=')
        );
        Storage::disk('public')->put(
            'students/profile-photo.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAusB9Y9l9WQAAAAASUVORK5CYII=')
        );
        $expectedProfilePhoto = 'data:image/png;base64,' . base64_encode(Storage::disk('public')->get('students/profile-avatar.png'));

        [, $admitCard] = $this->createPublishedAdmitCard();

        $admitCard->student->update(['avatar_url' => 'students/profile-photo.png']);
        $admitCard->student->profile()->updateOrCreate(
            ['student_id' => $admitCard->student->id],
            ['user_id' => $admitCard->student->user_id, 'avatar_url' => 'students/profile-avatar.png']
        );

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/v1/admits/{$admitCard->id}/paper");

        $response->assertOk();
        $photo = (string) data_get($response->json(), 'admit_card.photo_url');
        $this->assertSame($expectedProfilePhoto, $photo);
    }

    public function test_student_can_download_published_admit_pdf(): void
    {
        $this->seed(RbacSeeder::class);

        [$studentUser, $admitCard] = $this->createPublishedAdmitCard();

        Sanctum::actingAs($studentUser);

        $response = $this->get("/api/v1/admits/{$admitCard->id}/paper/download");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString(
            'attachment;',
            (string) $response->headers->get('content-disposition')
        );
    }

    private function createPublishedAdmitCard(): array
    {
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
            'blood_group' => 'O+',
            'address' => 'Naugawa',
            'city' => 'Yogapatti',
            'state' => 'Bihar',
            'pincode' => '845452',
            'status' => 'active',
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
            'name' => 'Mid Term',
            'status' => 'published',
        ]);

        $snapshot = AdmitScheduleSnapshot::create([
            'exam_session_id' => $session->id,
            'snapshot_version' => 1,
            'schedule_snapshot' => [
                'subjects' => [
                    [
                        'subject_id' => 1,
                        'subject_name' => 'Mathematics',
                        'subject_code' => 'MATH',
                        'exam_date' => '2026-03-20',
                        'exam_shift' => '1st Shift',
                        'start_time' => '10:00',
                        'end_time' => '13:00',
                    ],
                ],
            ],
            'created_at' => now(),
        ]);

        $admitCard = AdmitCard::create([
            'exam_session_id' => $session->id,
            'admit_schedule_snapshot_id' => $snapshot->id,
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'roll_number' => '12',
            'seat_number' => 'S-0001',
            'status' => 'published',
            'version' => 1,
            'is_superseded' => false,
            'generated_at' => now()->subDay(),
            'published_at' => now(),
            'verification_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'verification_hash' => hash('sha256', 'verification'),
            'verification_status' => 'active',
        ]);

        AdmitVisibilityControl::create([
            'admit_card_id' => $admitCard->id,
            'visibility_status' => 'visible',
            'visibility_version' => 1,
        ]);

        return [$studentUser, $admitCard];
    }
}
