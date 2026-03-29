<?php

namespace Tests\Feature;

use App\Jobs\NotifyPaymentRecordedJob;
use App\Models\AcademicYear;
use App\Models\ClassModel;
use App\Models\Enrollment;
use App\Models\FinancialPeriod;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\AccountingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceDurabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_payment_creates_balanced_gl_and_ledger_credit(): void
    {
        [$admin, $enrollment] = $this->bootstrapFinanceContext();
        Sanctum::actingAs($admin);
        Queue::fake();

        $response = $this->postJson('/api/v1/finance/payments', [
            'enrollment_id' => $enrollment->id,
            'amount' => 1200.50,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
        ]);

        $response->assertCreated();
        $paymentId = (int) $response->json('data.id');

        $this->assertDatabaseHas('student_fee_ledger', [
            'enrollment_id' => $enrollment->id,
            'reference_type' => 'payment',
            'reference_id' => $paymentId,
            'transaction_type' => 'credit',
        ]);

        $journalEntryId = (int) DB::table('journal_entries')
            ->where('source_type', 'payment')
            ->where('source_id', $paymentId)
            ->value('id');

        $this->assertGreaterThan(0, $journalEntryId);

        $lineTotals = DB::table('journal_lines')
            ->where('journal_entry_id', $journalEntryId)
            ->selectRaw('SUM(debit) as debits, SUM(credit) as credits')
            ->first();

        $this->assertSame(
            number_format((float) $lineTotals->debits, 2, '.', ''),
            number_format((float) $lineTotals->credits, 2, '.', '')
        );

        Queue::assertPushed(NotifyPaymentRecordedJob::class, function (NotifyPaymentRecordedJob $job) use ($paymentId) {
            return $job->paymentId === $paymentId
                && $job->queue === 'emails';
        });
    }

    public function test_refund_is_idempotent_and_duplicate_refund_is_blocked(): void
    {
        [$admin, $enrollment] = $this->bootstrapFinanceContext();
        Sanctum::actingAs($admin);

        $paymentResponse = $this->postJson('/api/v1/finance/payments', [
            'enrollment_id' => $enrollment->id,
            'amount' => 800,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'online',
        ])->assertCreated();

        $paymentId = (int) $paymentResponse->json('data.id');

        $this->postJson("/api/v1/finance/payments/{$paymentId}/refund", [
            'refund_reason' => 'Duplicate payment',
            'refund_date' => now()->toDateString(),
        ])->assertOk();

        $this->postJson("/api/v1/finance/payments/{$paymentId}/refund", [
            'refund_reason' => 'Retry should fail',
        ])->assertStatus(422);
    }

    public function test_posting_is_rejected_when_financial_period_is_locked(): void
    {
        [$admin, $enrollment] = $this->bootstrapFinanceContext();
        Sanctum::actingAs($admin);

        $today = now()->toDateString();
        $period = FinancialPeriod::query()
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->firstOrFail();

        $period->update([
            'is_locked' => true,
            'locked_at' => now(),
            'locked_by' => $admin->id,
        ]);

        $this->postJson('/api/v1/finance/payments', [
            'enrollment_id' => $enrollment->id,
            'amount' => 500,
            'payment_date' => $today,
            'payment_method' => 'cash',
        ])->assertStatus(422);
    }

    public function test_duplicate_journal_reversal_is_blocked(): void
    {
        [$admin, $enrollment] = $this->bootstrapFinanceContext();
        Sanctum::actingAs($admin);

        $paymentResponse = $this->postJson('/api/v1/finance/payments', [
            'enrollment_id' => $enrollment->id,
            'amount' => 1000,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'card',
        ])->assertCreated();

        $paymentId = (int) $paymentResponse->json('data.id');
        $ledgerId = (int) DB::table('student_fee_ledger')
            ->where('reference_type', 'payment')
            ->where('reference_id', $paymentId)
            ->value('id');

        $this->postJson("/api/v1/finance/ledger/{$ledgerId}/reverse", [
            'reason' => 'Manual correction',
        ])->assertCreated();

        $this->postJson("/api/v1/finance/ledger/{$ledgerId}/reverse", [
            'reason' => 'Second reversal should fail',
        ])->assertStatus(422);
    }

    private function bootstrapFinanceContext(): array
    {
        $this->seed(AccountingSeeder::class);

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

        return [$admin, $enrollment];
    }
}
