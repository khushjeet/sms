<?php

namespace Tests\Feature;

use App\Mail\GenericEventMail;
use App\Models\SchoolSetting;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentRegistrationNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_registration_sends_profile_pdf_email(): void
    {
        $this->seed(RbacSeeder::class);
        $this->enableSchoolMail();
        Mail::fake();

        Sanctum::actingAs($this->makeSuperAdmin());

        $payload = [
            'first_name' => 'Aman',
            'last_name' => 'Kumar',
            'email' => 'student+' . uniqid() . '@school.test',
            'phone' => '9999999999',
            'admission_number' => 'ADM-103',
            'admission_date' => now()->toDateString(),
            'date_of_birth' => '2012-01-15',
            'gender' => 'male',
            'father_name' => 'Ramesh Kumar',
            'father_email' => 'father+' . uniqid() . '@school.test',
        ];

        $response = $this->postJson('/api/v1/students', $payload);

        $response
            ->assertCreated()
            ->assertJsonPath('data.admission_number', 'ADM-103')
            ->assertJsonPath('data.user.email', $payload['email']);

        $student = Student::with(['user', 'profile', 'parents.user'])->firstOrFail();
        $filename = 'student-ADM-103.pdf';

        Mail::assertQueued(GenericEventMail::class, function (GenericEventMail $mail) use ($payload, $filename) {
            if (!$mail->hasTo($payload['email']) || !$mail->hasSubject('Student registration completed')) {
                return false;
            }

            return count($mail->rawAttachments) === 1
                && ($mail->rawAttachments[0]['name'] ?? null) === $filename
                && (($mail->rawAttachments[0]['options']['mime'] ?? null) === 'application/pdf');
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
