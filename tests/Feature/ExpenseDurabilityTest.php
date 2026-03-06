<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Account;
use App\Models\User;
use Database\Seeders\AccountingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpenseDurabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_expense_creates_balanced_journal_and_audit_logs(): void
    {
        $this->seed(AccountingSeeder::class);
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/finance/expenses', [
            'expense_date' => now()->toDateString(),
            'category' => 'Maintenance',
            'description' => 'Generator service and minor repairs',
            'vendor_name' => 'ABC Services',
            'amount' => 2500.75,
            'payment_method' => 'cash',
        ]);

        $response->assertCreated();
        $expenseId = (int) $response->json('data.id');

        $journalEntryId = (int) DB::table('journal_entries')
            ->where('source_type', 'expense')
            ->where('source_id', $expenseId)
            ->value('id');

        $this->assertGreaterThan(0, $journalEntryId);

        $totals = DB::table('journal_lines')
            ->where('journal_entry_id', $journalEntryId)
            ->selectRaw('SUM(debit) as debits, SUM(credit) as credits')
            ->first();

        $this->assertSame(
            number_format((float) $totals->debits, 2, '.', ''),
            number_format((float) $totals->credits, 2, '.', '')
        );

        $this->assertGreaterThanOrEqual(2, AuditLog::query()->count());
    }

    public function test_expense_reversal_is_idempotent(): void
    {
        $this->seed(AccountingSeeder::class);
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $expenseResponse = $this->postJson('/api/v1/finance/expenses', [
            'expense_date' => now()->toDateString(),
            'category' => 'Transport Fuel',
            'amount' => 1800,
            'payment_method' => 'online',
        ])->assertCreated();

        $expenseId = (int) $expenseResponse->json('data.id');

        $this->postJson("/api/v1/finance/expenses/{$expenseId}/reverse", [
            'reversal_reason' => 'Duplicate bill entry',
            'reversal_date' => now()->toDateString(),
        ])->assertCreated();

        $this->postJson("/api/v1/finance/expenses/{$expenseId}/reverse", [
            'reversal_reason' => 'Second reversal should fail',
        ])->assertStatus(422);
    }

    public function test_dashboard_includes_expense_section(): void
    {
        $this->seed(AccountingSeeder::class);
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/finance/expenses', [
            'expense_date' => now()->toDateString(),
            'category' => 'Utilities',
            'amount' => 999.99,
            'payment_method' => 'cheque',
        ])->assertCreated();

        $dashboard = $this->getJson('/api/v1/dashboard/super-admin')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('expense', $dashboard);
        $this->assertSame('999.99', number_format((float) ($dashboard['expense']['today_total'] ?? 0), 2, '.', ''));
    }

    public function test_expense_can_store_receipt_file_and_accept_supported_types(): void
    {
        Storage::fake('public');
        $this->seed(AccountingSeeder::class);
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $file = UploadedFile::fake()->create('maintenance-bill.pdf', 250, 'application/pdf');

        $response = $this->post('/api/v1/finance/expenses', [
            'expense_date' => now()->toDateString(),
            'category' => 'Maintenance',
            'amount' => 1200,
            'payment_method' => 'cash',
            'receipt_file' => $file,
        ]);

        $response->assertCreated();

        $receiptPath = DB::table('expense_receipts')->value('file_path');
        $this->assertNotNull($receiptPath);
        Storage::disk('public')->assertExists($receiptPath);
    }

    public function test_upload_rejects_unsupported_receipt_file_type(): void
    {
        Storage::fake('public');
        $this->seed(AccountingSeeder::class);
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $expenseId = (int) $this->postJson('/api/v1/finance/expenses', [
            'expense_date' => now()->toDateString(),
            'category' => 'Maintenance',
            'amount' => 500,
            'payment_method' => 'cash',
        ])->assertCreated()->json('data.id');

        $badFile = UploadedFile::fake()->create('virus.exe', 100, 'application/octet-stream');

        $this->post(
            "/api/v1/finance/expenses/{$expenseId}/receipts",
            ['receipt_file' => $badFile],
            ['Accept' => 'application/json']
        )->assertStatus(422);
    }

    public function test_expense_creation_auto_provisions_missing_expense_operating_account(): void
    {
        $this->seed(AccountingSeeder::class);
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        Account::query()->where('code', 'EXPENSE_OPERATING')->delete();

        $this->postJson('/api/v1/finance/expenses', [
            'expense_date' => now()->toDateString(),
            'category' => 'Office',
            'amount' => 700,
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->assertDatabaseHas('accounts', [
            'code' => 'EXPENSE_OPERATING',
            'type' => 'expense',
            'is_active' => 1,
        ]);
    }

    public function test_expense_audit_report_endpoint_returns_fingerprint_and_summary(): void
    {
        $this->seed(AccountingSeeder::class);
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/finance/expenses', [
            'expense_date' => now()->toDateString(),
            'category' => 'Utilities',
            'amount' => 333.33,
            'payment_method' => 'cash',
        ])->assertCreated();

        $response = $this->getJson('/api/v1/finance/reports/expenses/audit?group_by=month')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('summary', $response);
        $this->assertArrayHasKey('report_fingerprint', $response);
        $this->assertGreaterThan(0, strlen((string) $response['report_fingerprint']));
    }

    public function test_expense_entries_download_returns_csv(): void
    {
        $this->seed(AccountingSeeder::class);
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/finance/expenses', [
            'expense_date' => now()->toDateString(),
            'category' => 'Stationery',
            'amount' => 120.25,
            'payment_method' => 'cash',
        ])->assertCreated();

        $response = $this->get('/api/v1/finance/reports/expenses/entries/download');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('expense_number', $response->streamedContent());
    }

    private function makeAdmin(): User
    {
        return User::create([
            'email' => 'admin+' . uniqid() . '@school.test',
            'password' => 'password',
            'role' => 'super_admin',
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'status' => 'active',
        ]);
    }
}
