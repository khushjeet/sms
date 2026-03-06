<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PayrollBatch;
use App\Models\Staff;
use App\Models\StaffAttendanceMonthLock;
use App\Models\StaffAttendanceRecord;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HrPayrollDurabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_payroll_uses_salary_version_by_effective_date(): void
    {
        [$admin, $staff] = $this->bootstrapStaffContext();
        Sanctum::actingAs($admin);

        $template22000 = $this->postJson('/api/v1/hr/salary/templates', [
            'name' => 'Teacher-22000',
            'components' => [
                [
                    'component_name' => 'Basic',
                    'component_type' => 'earning',
                    'amount' => 22000,
                ],
            ],
        ])->assertCreated()->json('data.id');

        $template23000 = $this->postJson('/api/v1/hr/salary/templates', [
            'name' => 'Teacher-23000',
            'components' => [
                [
                    'component_name' => 'Basic',
                    'component_type' => 'earning',
                    'amount' => 23000,
                ],
            ],
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/hr/salary/assignments', [
            'staff_id' => $staff->id,
            'salary_template_id' => $template22000,
            'effective_from' => '2026-01-01',
        ])->assertCreated();

        $this->postJson('/api/v1/hr/salary/assignments', [
            'staff_id' => $staff->id,
            'salary_template_id' => $template23000,
            'effective_from' => '2026-07-01',
        ])->assertCreated();

        $this->seedFullAttendanceAndLock($staff->id, Carbon::create(2026, 6, 1));
        $this->seedFullAttendanceAndLock($staff->id, Carbon::create(2026, 7, 1));

        $juneBatchId = $this->postJson('/api/v1/hr/payroll/generate', [
            'year' => 2026,
            'month' => 6,
        ])->assertCreated()->json('data.id');

        $julyBatchId = $this->postJson('/api/v1/hr/payroll/generate', [
            'year' => 2026,
            'month' => 7,
        ])->assertCreated()->json('data.id');

        $juneNet = (float) PayrollBatch::query()->findOrFail($juneBatchId)->items()->value('net_pay');
        $julyNet = (float) PayrollBatch::query()->findOrFail($julyBatchId)->items()->value('net_pay');

        $this->assertSame(22000.0, $juneNet);
        $this->assertSame(23000.0, $julyNet);
    }

    public function test_finalized_payroll_is_immutable_and_uses_adjustments(): void
    {
        [$admin, $staff] = $this->bootstrapStaffContext();
        Sanctum::actingAs($admin);

        $templateId = $this->postJson('/api/v1/hr/salary/templates', [
            'name' => 'Teacher-30000',
            'components' => [
                [
                    'component_name' => 'Basic',
                    'component_type' => 'earning',
                    'amount' => 30000,
                ],
            ],
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/hr/salary/assignments', [
            'staff_id' => $staff->id,
            'salary_template_id' => $templateId,
            'effective_from' => '2026-01-01',
        ])->assertCreated();

        $this->seedFullAttendanceAndLock($staff->id, Carbon::create(2026, 8, 1));

        $batchId = $this->postJson('/api/v1/hr/payroll/generate', [
            'year' => 2026,
            'month' => 8,
        ])->assertCreated()->json('data.id');

        $batch = PayrollBatch::query()->with('items')->findOrFail($batchId);
        $item = $batch->items->firstOrFail();
        $originalNet = (float) $item->net_pay;

        $this->postJson("/api/v1/hr/payroll/{$batchId}/finalize")->assertOk();

        $this->postJson('/api/v1/hr/payroll/generate', [
            'year' => 2026,
            'month' => 8,
            'force_regenerate' => true,
        ])->assertStatus(422);

        $this->postJson("/api/v1/hr/payroll/{$batchId}/items/{$item->id}/adjustments", [
            'adjustment_type' => 'recovery',
            'amount' => -5000,
            'remarks' => 'Post-finalization recovery for overpayment',
        ])->assertCreated();

        $this->assertDatabaseHas('payroll_item_adjustments', [
            'payroll_batch_item_id' => $item->id,
            'adjustment_type' => 'recovery',
            'amount' => '-5000.00',
        ]);
        $this->assertSame($originalNet, (float) $item->fresh()->net_pay);
    }

    public function test_locked_attendance_edit_requires_override_and_logs_action(): void
    {
        [$admin, $staff] = $this->bootstrapStaffContext();
        Sanctum::actingAs($admin);

        StaffAttendanceMonthLock::query()->create([
            'year' => 2026,
            'month' => 9,
            'is_locked' => true,
            'locked_at' => now(),
            'locked_by' => $admin->id,
        ]);

        $this->postJson('/api/v1/hr/attendance/mark', [
            'staff_id' => $staff->id,
            'date' => '2026-09-05',
            'status' => 'present',
        ])->assertStatus(422);

        $this->postJson('/api/v1/hr/attendance/mark', [
            'staff_id' => $staff->id,
            'date' => '2026-09-05',
            'status' => 'absent',
            'override_locked_month' => true,
            'override_reason' => 'Correction after register audit',
        ])->assertOk();

        $this->assertDatabaseHas('staff_attendance_records', [
            'staff_id' => $staff->id,
            'attendance_date' => '2026-09-05',
            'status' => 'absent',
        ]);
        $this->assertTrue(
            AuditLog::query()
                ->where('action', 'attendance.override')
                ->where('reason', 'Correction after register audit')
                ->exists()
        );
    }

    private function bootstrapStaffContext(): array
    {
        $admin = User::create([
            'email' => 'hr-admin+' . uniqid() . '@school.test',
            'password' => 'password',
            'role' => 'super_admin',
            'first_name' => 'HR',
            'last_name' => 'Admin',
            'status' => 'active',
        ]);

        $staffUser = User::create([
            'email' => 'employee+' . uniqid() . '@school.test',
            'password' => 'password',
            'role' => 'staff',
            'first_name' => 'Test',
            'last_name' => 'Employee',
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

    private function seedFullAttendanceAndLock(int $staffId, Carbon $monthStart): void
    {
        $days = (int) $monthStart->copy()->daysInMonth;
        for ($day = 1; $day <= $days; $day++) {
            StaffAttendanceRecord::query()->create([
                'staff_id' => $staffId,
                'attendance_date' => $monthStart->copy()->day($day)->toDateString(),
                'status' => 'present',
            ]);
        }

        StaffAttendanceMonthLock::query()->updateOrCreate(
            [
                'year' => (int) $monthStart->year,
                'month' => (int) $monthStart->month,
            ],
            [
                'is_locked' => true,
                'locked_at' => now(),
            ]
        );
    }
}
