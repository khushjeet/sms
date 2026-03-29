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

class MessageCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_send_message_to_selected_students_and_parents_via_email(): void
    {
        $this->seed(RbacSeeder::class);
        $this->enableSchoolMail();
        config(['queue.default' => 'sync']);
        Mail::fake();

        Sanctum::actingAs($this->makeSuperAdmin());

        $studentUser = User::create([
            'email' => 'student+' . uniqid() . '@school.test',
            'password' => 'password',
            'role' => 'student',
            'first_name' => 'Aman',
            'last_name' => 'Kumar',
            'status' => 'active',
        ]);
        $studentUser->assignRole('student');

        $student = Student::create([
            'user_id' => $studentUser->id,
            'admission_number' => 'ADM-500',
            'admission_date' => now()->toDateString(),
            'date_of_birth' => '2012-01-15',
            'gender' => 'male',
            'status' => 'active',
        ]);

        StudentProfile::create([
            'student_id' => $student->id,
            'user_id' => $studentUser->id,
            'father_name' => 'Rajesh Kumar',
            'father_email' => 'father+' . uniqid() . '@school.test',
            'mother_name' => 'Sunita Kumar',
            'mother_email' => 'mother+' . uniqid() . '@school.test',
        ]);

        $response = $this->postJson('/api/v1/message-center/send', [
            'language' => 'english',
            'channel' => 'email',
            'audience' => 'both',
            'subject' => 'Fee reminder',
            'message' => "Dear family,\nPlease review the latest school notice.",
            'student_ids' => [$student->id],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.channel', 'email')
            ->assertJsonPath('data.audience', 'both')
            ->assertJsonPath('data.students_count', 1)
            ->assertJsonPath('data.recipient_count', 3)
            ->assertJsonPath('data.queued_count', 0)
            ->assertJsonPath('data.delivered_count', 3)
            ->assertJsonPath('data.failed_count', 0);

        $batchId = (string) $response->json('data.batch_id');

        $this->getJson('/api/v1/message-center/status/' . $batchId)
            ->assertOk()
            ->assertJsonPath('queued_count', 0)
            ->assertJsonPath('delivered_count', 3)
            ->assertJsonPath('failed_count', 0);

        Mail::assertSent(GenericEventMail::class, 3);
    }

    public function test_super_admin_can_schedule_special_email(): void
    {
        $this->seed(RbacSeeder::class);
        $this->enableSchoolMail();

        Sanctum::actingAs($this->makeSuperAdmin());

        $student = $this->makeStudentWithProfile('ADM-700');

        $response = $this->postJson('/api/v1/message-center/send', [
            'language' => 'english',
            'channel' => 'email',
            'audience' => 'parents',
            'subject' => 'Annual function reminder',
            'message' => 'Please attend the annual function tomorrow.',
            'student_ids' => [$student->id],
            'schedule_at' => now()->addHour()->toDateTimeString(),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.scheduled', true);

        $this->assertDatabaseHas('scheduled_messages', [
            'subject' => 'Annual function reminder',
            'status' => 'scheduled',
        ]);
    }

    public function test_birthday_wish_command_sends_email_for_todays_birthdays(): void
    {
        $this->seed(RbacSeeder::class);
        $this->enableSchoolMail();
        config(['queue.default' => 'sync']);
        Mail::fake();

        $this->makeStudentWithProfile('ADM-900', now()->toDateString());

        SchoolSetting::putValue('birthday_email_enabled', '1');
        SchoolSetting::putValue('birthday_email_audience', 'both');
        SchoolSetting::putValue('birthday_email_subject', 'Happy Birthday');
        SchoolSetting::putValue('birthday_email_message', 'Have a wonderful birthday.');
        SchoolSetting::putValue('birthday_email_send_time', now()->subMinute()->format('H:i'));

        $this->artisan('message-center:send-birthday-wishes')
            ->assertExitCode(0);

        $this->assertSame(now()->toDateString(), SchoolSetting::getValue('birthday_email_last_sent_on'));
        Mail::assertSent(GenericEventMail::class, 3);
    }

    public function test_scheduled_message_command_dispatches_due_email(): void
    {
        $this->seed(RbacSeeder::class);
        $this->enableSchoolMail();
        config(['queue.default' => 'sync']);
        Mail::fake();

        $student = $this->makeStudentWithProfile('ADM-910');

        $scheduled = \App\Models\ScheduledMessage::create([
            'language' => 'english',
            'channel' => 'email',
            'audience' => 'parents',
            'subject' => 'PTM reminder',
            'message' => 'Parent-teacher meeting tomorrow.',
            'student_ids' => [$student->id],
            'scheduled_for' => now()->subMinute(),
            'status' => 'scheduled',
        ]);

        $this->artisan('message-center:process-scheduled')
            ->assertExitCode(0);

        $scheduled->refresh();

        $this->assertSame('sent', $scheduled->status);
        $this->assertNotNull($scheduled->batch_id);
        Mail::assertSent(GenericEventMail::class, 2);
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

    private function makeStudentWithProfile(string $admissionNumber, ?string $dateOfBirth = '2012-01-15'): Student
    {
        $studentUser = User::create([
            'email' => 'student+' . uniqid() . '@school.test',
            'password' => 'password',
            'role' => 'student',
            'first_name' => 'Aman',
            'last_name' => 'Kumar',
            'status' => 'active',
        ]);
        $studentUser->assignRole('student');

        $student = Student::create([
            'user_id' => $studentUser->id,
            'admission_number' => $admissionNumber,
            'admission_date' => now()->toDateString(),
            'date_of_birth' => $dateOfBirth,
            'gender' => 'male',
            'status' => 'active',
        ]);

        StudentProfile::create([
            'student_id' => $student->id,
            'user_id' => $studentUser->id,
            'father_name' => 'Rajesh Kumar',
            'father_email' => 'father+' . uniqid() . '@school.test',
            'mother_name' => 'Sunita Kumar',
            'mother_email' => 'mother+' . uniqid() . '@school.test',
        ]);

        return $student;
    }
}
