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

class StudentUpdateNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_update_sends_changed_fields_email(): void
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
        $user->assignRole('student');

        $student = Student::create([
            'user_id' => $user->id,
            'admission_number' => 'ADM-102',
            'admission_date' => now()->toDateString(),
            'date_of_birth' => '2012-01-15',
            'gender' => 'male',
            'blood_group' => 'O+',
            'address' => 'Naugawa',
            'status' => 'active',
        ]);

        StudentProfile::create([
            'student_id' => $student->id,
            'user_id' => $user->id,
            'father_name' => 'Ramesh Kumar',
            'mother_name' => 'Sita Devi',
            'mother_email' => 'mother+' . uniqid() . '@school.test',
            'roll_number' => '12',
        ]);

        $response = $this->putJson("/api/v1/students/{$student->id}", [
            'remarks' => 'Updated by admin',
            'mother_name' => 'Sunita Devi',
            'roll_number' => '13',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.remarks', 'Updated by admin')
            ->assertJsonPath('data.profile.mother_name', 'Sunita Devi')
            ->assertJsonPath('data.profile.roll_number', '13');

        Mail::assertQueued(GenericEventMail::class, function (GenericEventMail $mail) use ($user) {
            if (!$mail->hasTo($user->email) || !$mail->hasSubject('Student profile updated')) {
                return false;
            }

            $mail->assertSeeInHtml('Roll Number: 12 -> 13');
            $mail->assertSeeInHtml('Mother Name: Sita Devi -> Sunita Devi');
            $mail->assertSeeInHtml('Remarks: - -> Updated by admin');

            return true;
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
