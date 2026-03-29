<?php

namespace Tests\Feature;

use App\Mail\GenericEventMail;
use App\Models\SchoolSetting;
use App\Models\Student;
use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentPdfDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_download_student_profile_pdf(): void
    {
        $this->seed(RbacSeeder::class);
        $this->enableSchoolMail();
        Mail::fake();

        Sanctum::actingAs($this->makeSuperAdmin());

        $user = User::create([
            'email' => 'student+' . uniqid() . '@school.test',
            'password' => 'password',
            'role' => 'student',
            'first_name' => 'Aman',
            'last_name' => 'Kumar',
            'status' => 'active',
        ]);

        $student = Student::create([
            'user_id' => $user->id,
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

        StudentProfile::create([
            'student_id' => $student->id,
            'user_id' => $user->id,
            'roll_number' => '12',
            'father_name' => 'Ramesh Kumar',
            'mother_name' => 'Sita Devi',
            'father_mobile_number' => '9999999999',
            'mother_mobile_number' => '8888888888',
            'bank_account_number' => '1234567890',
            'bank_account_holder' => 'Ramesh Kumar',
            'ifsc_code' => 'SBIN0001234',
            'relation_with_account_holder' => 'Father',
            'permanent_address' => 'Naugawa, Yogapatti',
            'current_address' => 'Naugawa, Yogapatti',
        ]);

        $response = $this->get("/api/v1/students/{$student->id}/pdf");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');

        $pdfContent = $response->getContent();
        $filename = 'student-ADM-101.pdf';

        Mail::assertQueued(GenericEventMail::class, function (GenericEventMail $mail) use ($user, $pdfContent, $filename) {
            return $mail->hasTo($user->email)
                && $mail->hasSubject('Student profile PDF shared')
                && $mail->hasAttachedData($pdfContent, $filename, ['mime' => 'application/pdf']);
        });
    }

    private function makeSuperAdmin(): User
    {
        $user = User::create([
            'email' => 'superadmin+' . uniqid() . '@school.test',
            'password' => 'password',
            'role' => 'super_admin',
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'status' => 'active',
        ]);

        $user->assignRole('super_admin');

        return $user;
    }

    private function enableSchoolMail(): void
    {
        SchoolSetting::putValue('smtp_enabled', '1');
        SchoolSetting::putValue('smtp_host', 'smtp.test.local');
        SchoolSetting::putValue('smtp_port', '2525');
        SchoolSetting::putValue('smtp_from_address', 'noreply@school.test');
        SchoolSetting::putValue('smtp_from_name', 'School Test');
    }
}
