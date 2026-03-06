<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeDurableHistoryExportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_attendance_history_and_export_work(): void
    {
        [$admin, $staff] = $this->bootstrapEmployeeContext();
        Sanctum::actingAs($admin);

        DB::table('staff_attendance_records')->insert([
            [
                'staff_id' => $staff->id,
                'attendance_date' => '2026-01-02',
                'status' => 'present',
                'late_minutes' => 5,
                'remarks' => 'Morning delay',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'staff_id' => $staff->id,
                'attendance_date' => '2026-01-03',
                'status' => 'absent',
                'late_minutes' => null,
                'remarks' => 'Leave without notice',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->getJson("/api/v1/employees/{$staff->id}/attendance-history")
            ->assertOk()
            ->assertJsonPath('total', 2);

        $download = $this->get("/api/v1/employees/{$staff->id}/attendance-history/download");
        $download->assertOk();
        $download->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $download->streamedContent();
        $this->assertStringContainsString('employee_id', $csv);
        $this->assertStringContainsString('Morning delay', $csv);
    }

    public function test_employee_payout_history_and_export_work(): void
    {
        [$admin, $staff] = $this->bootstrapEmployeeContext();
        Sanctum::actingAs($admin);

        $batchId = DB::table('payroll_batches')->insertGetId([
            'year' => 2026,
            'month' => 1,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => 'paid',
            'is_locked' => true,
            'generated_at' => now(),
            'generated_by' => $admin->id,
            'finalized_at' => now(),
            'finalized_by' => $admin->id,
            'paid_at' => now(),
            'paid_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('payroll_batch_items')->insertGetId([
            'payroll_batch_id' => $batchId,
            'staff_id' => $staff->id,
            'staff_salary_structure_id' => null,
            'days_in_month' => 31,
            'payable_days' => 31,
            'leave_days' => 0,
            'absent_days' => 0,
            'gross_pay' => 30000,
            'total_deductions' => 2000,
            'net_pay' => 28000,
            'snapshot' => json_encode(['source' => 'test']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payroll_item_adjustments')->insert([
            'payroll_batch_item_id' => $itemId,
            'adjustment_type' => 'correction',
            'amount' => -500,
            'remarks' => 'Overpayment recovery',
            'created_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson("/api/v1/employees/{$staff->id}/payout-history")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.net_after_adjustment', '27500.00');

        $download = $this->get("/api/v1/employees/{$staff->id}/payout-history/download");
        $download->assertOk();
        $download->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $download->streamedContent();
        $this->assertStringContainsString('net_after_adjustment', $csv);
        $this->assertStringContainsString('27500.00', $csv);
    }

    private function bootstrapEmployeeContext(): array
    {
        $admin = User::create([
            'email' => 'admin+' . uniqid() . '@school.test',
            'password' => 'password',
            'role' => 'super_admin',
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'status' => 'active',
        ]);

        $staffUser = User::create([
            'email' => 'staff+' . uniqid() . '@school.test',
            'password' => 'password',
            'role' => 'staff',
            'first_name' => 'Staff',
            'last_name' => 'Member',
            'status' => 'active',
        ]);

        $staff = Staff::create([
            'user_id' => $staffUser->id,
            'employee_id' => 'EMP-' . random_int(1000, 9999),
            'joining_date' => '2026-01-01',
            'employee_type' => 'teaching',
            'designation' => 'Teacher',
            'department' => 'Academic',
            'salary' => 0,
            'date_of_birth' => '1990-01-01',
            'gender' => 'male',
            'status' => 'active',
        ]);

        return [$admin, $staff];
    }
}
