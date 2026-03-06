<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassModel;
use App\Models\Enrollment;
use App\Models\FeeHead;
use App\Models\FeeInstallment;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InstallmentNarrationRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_installment_assignment_persists_narration_and_appears_in_ledger_download(): void
    {
        [$admin, $student, $enrollment, $installment] = $this->bootstrapContext();
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/v1/finance/enrollments/{$enrollment->id}/installments", [
            'fee_installment_id' => $installment->id,
            'amount' => 1250,
        ]);
        $response->assertCreated();

        $expectedNarration = 'Installment assigned: ' . $installment->name;
        $this->assertDatabaseHas('student_fee_ledger', [
            'enrollment_id' => $enrollment->id,
            'reference_type' => 'fee_installment',
            'transaction_type' => 'debit',
            'narration' => $expectedNarration,
        ]);

        $ledgerResponse = $this->getJson("/api/v1/finance/students/{$student->id}/ledger");
        $ledgerResponse->assertOk();
        $ledgerResponse->assertJsonFragment(['narration' => $expectedNarration]);

        $download = $this->get("/api/v1/finance/students/{$student->id}/ledger/download");
        $download->assertOk();
        $download->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString($expectedNarration, $download->streamedContent());
    }

    public function test_bulk_installment_assignment_persists_narration_with_installment_name(): void
    {
        [$admin, $student, $enrollment, $installment] = $this->bootstrapContext();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/finance/installments/assign-to-class', [
            'fee_installment_id' => $installment->id,
            'class_id' => $enrollment->class_id,
            'academic_year_id' => $enrollment->academic_year_id,
            'amount' => 1350,
        ]);

        $response->assertOk();
        $response->assertJsonFragment(['assigned_count' => 1]);

        $expectedNarration = 'Installment assigned: ' . $installment->name;
        $ledgerResponse = $this->getJson("/api/v1/finance/students/{$student->id}/ledger");
        $ledgerResponse->assertOk();
        $ledgerResponse->assertJsonFragment(['narration' => $expectedNarration]);
    }

    private function bootstrapContext(): array
    {
        $admin = User::create([
            'email' => 'admin+' . uniqid() . '@school.test',
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
            'first_name' => 'Test',
            'last_name' => 'Student',
            'status' => 'active',
        ]);

        $student = Student::create([
            'user_id' => $studentUser->id,
            'admission_number' => 'ADM-' . random_int(10000, 99999),
            'admission_date' => now()->toDateString(),
            'date_of_birth' => now()->subYears(10)->toDateString(),
            'gender' => 'male',
            'status' => 'active',
        ]);

        $academicYear = AcademicYear::create([
            'name' => 'AY ' . now()->year,
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'status' => 'active',
            'is_current' => true,
        ]);

        $class = ClassModel::create([
            'name' => 'Class Test',
            'numeric_order' => 1,
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
            'roll_number' => 1,
            'enrollment_date' => now()->toDateString(),
            'status' => 'active',
            'is_locked' => false,
        ]);

        $feeHead = FeeHead::create([
            'name' => 'Tuition Fee',
            'code' => 'TUI',
            'description' => 'Tuition',
            'status' => 'active',
        ]);

        $installment = FeeInstallment::create([
            'fee_head_id' => $feeHead->id,
            'class_id' => $class->id,
            'academic_year_id' => $academicYear->id,
            'name' => 'April 2026',
            'due_date' => now()->addDays(10)->toDateString(),
            'amount' => 1200,
            'status' => 'active',
        ]);

        return [$admin, $student, $enrollment, $installment];
    }
}
