<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassModel;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubjectApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_subject_with_compatibility_fields(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin());

        $response = $this->postJson('/api/v1/subjects', [
            'name' => 'Mathematics',
            'subject_code' => 'math_101',
            'type' => 'core',
            'is_active' => true,
            'credits' => 4,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.subject_code', 'MATH_101')
            ->assertJsonPath('data.code', 'MATH_101')
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('subjects', [
            'name' => 'Mathematics',
            'subject_code' => 'MATH_101',
            'code' => 'MATH_101',
            'status' => 'active',
            'is_active' => 1,
        ]);
    }

    public function test_subject_index_supports_search_and_status_filters(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin());

        Subject::create([
            'name' => 'Physics',
            'code' => 'PHY_101',
            'subject_code' => 'PHY_101',
            'type' => 'core',
            'status' => 'active',
            'is_active' => true,
        ]);

        Subject::create([
            'name' => 'Painting',
            'code' => 'ART_101',
            'subject_code' => 'ART_101',
            'type' => 'elective',
            'status' => 'inactive',
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/v1/subjects?search=phy&status=active');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Physics', $response->json('data.0.name'));
    }

    public function test_update_keeps_legacy_code_in_sync(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin());

        $subject = Subject::create([
            'name' => 'Biology',
            'code' => 'BIO_101',
            'subject_code' => 'BIO_101',
            'type' => 'core',
            'status' => 'active',
            'is_active' => true,
        ]);

        $response = $this->putJson("/api/v1/subjects/{$subject->id}", [
            'subject_code' => 'bio_advanced',
            'is_active' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.subject_code', 'BIO_ADVANCED')
            ->assertJsonPath('data.code', 'BIO_ADVANCED')
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_subject_delete_is_blocked_when_referenced(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin());

        $subject = Subject::create([
            'name' => 'Chemistry',
            'code' => 'CHEM_101',
            'subject_code' => 'CHEM_101',
            'type' => 'core',
            'status' => 'active',
            'is_active' => true,
        ]);

        $year = AcademicYear::create([
            'name' => 'AY ' . now()->year,
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'status' => 'active',
            'is_current' => true,
        ]);

        $class = ClassModel::create([
            'name' => 'Class X',
            'numeric_order' => 10,
            'status' => 'active',
        ]);

        DB::table('class_subjects')->insert([
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $year->id,
            'max_marks' => 100,
            'pass_marks' => 35,
            'is_mandatory' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson("/api/v1/subjects/{$subject->id}")
            ->assertStatus(422)
            ->assertJsonPath('dependencies.class_subjects', 1);
    }

    public function test_subject_detail_includes_class_mappings(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin());

        $subject = Subject::create([
            'name' => 'History',
            'code' => 'HIST_101',
            'subject_code' => 'HIST_101',
            'type' => 'core',
            'status' => 'active',
            'is_active' => true,
        ]);

        $year = AcademicYear::create([
            'name' => 'AY ' . now()->year,
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'status' => 'active',
            'is_current' => true,
        ]);

        $class = ClassModel::create([
            'name' => 'Class IX',
            'numeric_order' => 9,
            'status' => 'active',
        ]);

        DB::table('class_subjects')->insert([
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $year->id,
            'max_marks' => 100,
            'pass_marks' => 35,
            'is_mandatory' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson("/api/v1/subjects/{$subject->id}")
            ->assertOk()
            ->assertJsonPath('classes.0.id', $class->id)
            ->assertJsonPath('classes.0.pivot.academic_year_id', $year->id)
            ->assertJsonPath('classes.0.pivot.max_marks', 100)
            ->assertJsonPath('classes.0.pivot.pass_marks', 35)
            ->assertJsonPath('classes.0.pivot.is_mandatory', 1);
    }

    public function test_super_admin_can_upsert_subject_class_mapping(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin());

        $subject = Subject::create([
            'name' => 'Computer Science',
            'code' => 'CS_101',
            'subject_code' => 'CS_101',
            'type' => 'core',
            'status' => 'active',
            'is_active' => true,
        ]);

        $year = AcademicYear::create([
            'name' => 'AY ' . now()->year,
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'status' => 'active',
            'is_current' => true,
        ]);

        $class = ClassModel::create([
            'name' => 'Class XI',
            'numeric_order' => 11,
            'status' => 'active',
        ]);

        $this->postJson("/api/v1/subjects/{$subject->id}/class-mappings", [
            'class_id' => $class->id,
            'academic_year_id' => $year->id,
            'max_marks' => 100,
            'pass_marks' => 35,
            'is_mandatory' => true,
        ])->assertOk();

        $this->postJson("/api/v1/subjects/{$subject->id}/class-mappings", [
            'class_id' => $class->id,
            'academic_year_id' => $year->id,
            'max_marks' => 120,
            'pass_marks' => 45,
            'is_mandatory' => false,
        ])->assertOk();

        $this->assertSame(
            1,
            DB::table('class_subjects')
                ->where('subject_id', $subject->id)
                ->where('class_id', $class->id)
                ->where('academic_year_id', $year->id)
                ->count()
        );

        $this->assertDatabaseHas('class_subjects', [
            'subject_id' => $subject->id,
            'class_id' => $class->id,
            'academic_year_id' => $year->id,
            'max_marks' => 120,
            'pass_marks' => 45,
            'is_mandatory' => 0,
        ]);
    }

    public function test_super_admin_can_remove_subject_class_mapping(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin());

        $subject = Subject::create([
            'name' => 'Economics',
            'code' => 'ECO_101',
            'subject_code' => 'ECO_101',
            'type' => 'core',
            'status' => 'active',
            'is_active' => true,
        ]);

        $year = AcademicYear::create([
            'name' => 'AY ' . now()->year,
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'status' => 'active',
            'is_current' => true,
        ]);

        $class = ClassModel::create([
            'name' => 'Class XII',
            'numeric_order' => 12,
            'status' => 'active',
        ]);

        DB::table('class_subjects')->insert([
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $year->id,
            'max_marks' => 100,
            'pass_marks' => 35,
            'is_mandatory' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson("/api/v1/subjects/{$subject->id}/class-mappings/{$class->id}/{$year->id}")
            ->assertOk();

        $this->assertDatabaseMissing('class_subjects', [
            'subject_id' => $subject->id,
            'class_id' => $class->id,
            'academic_year_id' => $year->id,
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
