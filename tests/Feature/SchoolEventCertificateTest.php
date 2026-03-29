<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassModel;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SchoolEventCertificateTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_event_and_sync_participants(): void
    {
        [$admin, $year, $enrollment] = $this->seedEventContext();
        Sanctum::actingAs($admin);

        $eventResponse = $this->postJson('/api/v1/events', [
            'academic_year_id' => $year->id,
            'title' => 'Annual Sports Day',
            'event_date' => now()->toDateString(),
            'venue' => 'Main Ground',
            'status' => 'draft',
            'certificate_prefix' => 'SPORT',
        ])->assertCreated();

        $eventId = (int) $eventResponse->json('data.id');

        $participantsResponse = $this->putJson("/api/v1/events/{$eventId}/participants", [
            'participants' => [[
                'student_id' => $enrollment->student_id,
                'enrollment_id' => $enrollment->id,
                'rank' => 1,
                'achievement_title' => '100m Race',
                'remarks' => 'Fastest runner',
            ]],
        ])->assertOk();

        $participantsResponse
            ->assertJsonPath('data.id', $eventId)
            ->assertJsonPath('data.participants.0.student_id', $enrollment->student_id)
            ->assertJsonPath('data.participants.0.rank', 1);

        $this->assertDatabaseHas('school_events', [
            'id' => $eventId,
            'title' => 'Annual Sports Day',
        ]);

        $this->assertDatabaseHas('school_event_participants', [
            'school_event_id' => $eventId,
            'student_id' => $enrollment->student_id,
            'rank' => 1,
        ]);
    }

    public function test_super_admin_can_download_participant_and_winner_certificates(): void
    {
        [$admin, $year, $enrollment] = $this->seedEventContext();
        Sanctum::actingAs($admin);

        $eventId = (int) $this->postJson('/api/v1/events', [
            'academic_year_id' => $year->id,
            'title' => 'Inter House Quiz',
            'event_date' => now()->toDateString(),
            'venue' => 'Assembly Hall',
            'status' => 'published',
        ])->json('data.id');

        $participantId = (int) $this->putJson("/api/v1/events/{$eventId}/participants", [
            'participants' => [[
                'student_id' => $enrollment->student_id,
                'enrollment_id' => $enrollment->id,
                'rank' => 2,
                'achievement_title' => 'Quiz Finals',
            ]],
        ])->json('data.participants.0.id');

        $this->get("/api/v1/events/participants/{$participantId}/certificate?type=participant")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->get("/api/v1/events/participants/{$participantId}/certificate?type=winner")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    private function seedEventContext(): array
    {
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
            'first_name' => 'Event',
            'last_name' => 'Student',
            'status' => 'active',
        ]);

        $student = Student::create([
            'user_id' => $studentUser->id,
            'admission_number' => 'ADM-' . random_int(1000, 9999),
            'admission_date' => now()->toDateString(),
            'date_of_birth' => now()->subYears(10)->toDateString(),
            'gender' => 'male',
            'status' => 'active',
        ]);

        $year = AcademicYear::create([
            'name' => 'AY ' . now()->year,
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'status' => 'active',
            'is_current' => true,
        ]);

        $class = ClassModel::create([
            'name' => 'Class Event',
            'numeric_order' => 1,
            'status' => 'active',
        ]);

        $section = Section::create([
            'class_id' => $class->id,
            'academic_year_id' => $year->id,
            'name' => 'A',
            'capacity' => 40,
            'status' => 'active',
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'class_id' => $class->id,
            'section_id' => $section->id,
            'roll_number' => 1,
            'enrollment_date' => now()->toDateString(),
            'status' => 'active',
            'is_locked' => false,
        ]);

        return [$admin, $year, $enrollment];
    }
}
