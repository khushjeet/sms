<?php

namespace Database\Seeders;

use App\Models\LeaveLedgerEntry;
use App\Models\PayrollBatch;
use App\Models\PayrollBatchItem;
use App\Models\PayrollItemAdjustment;
use App\Models\SalaryTemplate;
use App\Models\Staff;
use App\Models\StaffAttendanceMonthLock;
use App\Models\StaffAttendanceRecord;
use App\Models\StaffSalaryStructure;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class HrPayrollDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $adminUser = $this->resolveAdminUser();
            $accountantUser = $this->resolveAccountantUser();
            $staffRows = $this->seedStaffUsersAndProfiles();
            $leaveTypes = $this->seedLeaveTypes();

            $teachingTemplate = $this->seedTeachingTemplate($adminUser->id);
            $adminTemplate = $this->seedAdminTemplate($adminUser->id);

            $this->seedSalaryStructures($staffRows, $teachingTemplate->id, $adminTemplate->id, $adminUser->id);

            $period = Carbon::now()->subMonthNoOverflow()->startOfMonth();
            $this->seedAttendanceMonthLock($period, $adminUser->id);
            $this->seedAttendanceRecords($staffRows, $period, $adminUser->id);
            $this->seedLeavesAndLedger($staffRows, $leaveTypes, $period, $adminUser->id);
            $this->seedPayrollSnapshot($staffRows, $period, $accountantUser->id);
        });
    }

    private function resolveAdminUser(): User
    {
        $existing = User::query()
            ->whereIn('role', ['super_admin', 'school_admin'])
            ->where('status', 'active')
            ->orderBy('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        return User::query()->firstOrCreate(
            ['email' => 'payroll.admin@example.com'],
            [
                'password' => Hash::make('password'),
                'role' => 'school_admin',
                'first_name' => 'Payroll',
                'last_name' => 'Admin',
                'status' => 'active',
            ]
        );
    }

    private function resolveAccountantUser(): User
    {
        $existing = User::query()
            ->where('role', 'accountant')
            ->where('status', 'active')
            ->orderBy('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        return User::query()->firstOrCreate(
            ['email' => 'payroll.accountant@example.com'],
            [
                'password' => Hash::make('password'),
                'role' => 'accountant',
                'first_name' => 'Payroll',
                'last_name' => 'Accountant',
                'status' => 'active',
            ]
        );
    }

    /**
     * @return array<int, Staff>
     */
    private function seedStaffUsersAndProfiles(): array
    {
        $rows = [];
        $seedRows = [
            [
                'email' => 'staff.maya@example.com',
                'first_name' => 'Maya',
                'last_name' => 'Sharma',
                'employee_id' => 'EMP-HR-001',
                'employee_type' => 'teaching',
                'designation' => 'Mathematics Teacher',
                'department' => 'Academics',
                'salary' => 38000,
                'gender' => 'female',
            ],
            [
                'email' => 'staff.arjun@example.com',
                'first_name' => 'Arjun',
                'last_name' => 'Singh',
                'employee_id' => 'EMP-HR-002',
                'employee_type' => 'teaching',
                'designation' => 'Science Teacher',
                'department' => 'Academics',
                'salary' => 36000,
                'gender' => 'male',
            ],
            [
                'email' => 'staff.neha@example.com',
                'first_name' => 'Neha',
                'last_name' => 'Gupta',
                'employee_id' => 'EMP-HR-003',
                'employee_type' => 'non_teaching',
                'designation' => 'Account Assistant',
                'department' => 'Accounts',
                'salary' => 32000,
                'gender' => 'female',
            ],
        ];

        foreach ($seedRows as $seed) {
            $user = User::query()->firstOrCreate(
                ['email' => $seed['email']],
                [
                    'password' => Hash::make('password'),
                    'role' => 'staff',
                    'first_name' => $seed['first_name'],
                    'last_name' => $seed['last_name'],
                    'status' => 'active',
                ]
            );

            $staff = Staff::query()->firstOrCreate(
                ['employee_id' => $seed['employee_id']],
                [
                    'user_id' => $user->id,
                    'joining_date' => Carbon::now()->subYears(2)->toDateString(),
                    'employee_type' => $seed['employee_type'],
                    'designation' => $seed['designation'],
                    'department' => $seed['department'],
                    'qualification' => 'Graduate',
                    'salary' => $seed['salary'],
                    'date_of_birth' => Carbon::now()->subYears(30)->toDateString(),
                    'gender' => $seed['gender'],
                    'address' => 'Demo staff address',
                    'emergency_contact' => '9999999999',
                    'status' => 'active',
                ]
            );

            if ((int) $staff->user_id !== (int) $user->id) {
                $staff->update(['user_id' => $user->id]);
            }

            $rows[] = $staff->fresh();
        }

        return $rows;
    }

    /**
     * @return array<string, int>
     */
    private function seedLeaveTypes(): array
    {
        $types = [
            ['name' => 'Casual Leave', 'max_days_per_year' => 12, 'is_paid' => true],
            ['name' => 'Sick Leave', 'max_days_per_year' => 10, 'is_paid' => true],
            ['name' => 'Loss Of Pay', 'max_days_per_year' => 0, 'is_paid' => false],
        ];

        $map = [];
        foreach ($types as $type) {
            DB::table('leave_types')->updateOrInsert(
                ['name' => $type['name']],
                [
                    'max_days_per_year' => $type['max_days_per_year'],
                    'is_paid' => $type['is_paid'],
                    'description' => 'Seeded by HrPayrollDemoSeeder',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            $map[$type['name']] = (int) DB::table('leave_types')->where('name', $type['name'])->value('id');
        }

        return $map;
    }

    private function seedTeachingTemplate(int $userId): SalaryTemplate
    {
        $template = SalaryTemplate::query()->firstOrCreate(
            ['name' => 'Demo Teaching Payroll Template'],
            [
                'description' => 'Monthly teaching payroll with fixed earnings and deductions.',
                'is_active' => true,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]
        );

        $template->components()->delete();
        $template->components()->createMany([
            ['component_name' => 'Basic', 'component_type' => 'earning', 'amount' => 24000, 'percentage' => null, 'is_taxable' => true, 'sort_order' => 1],
            ['component_name' => 'HRA', 'component_type' => 'earning', 'amount' => 8000, 'percentage' => null, 'is_taxable' => false, 'sort_order' => 2],
            ['component_name' => 'Special Allowance', 'component_type' => 'earning', 'amount' => 6000, 'percentage' => null, 'is_taxable' => true, 'sort_order' => 3],
            ['component_name' => 'PF', 'component_type' => 'deduction', 'amount' => 2200, 'percentage' => null, 'is_taxable' => false, 'sort_order' => 4],
            ['component_name' => 'Professional Tax', 'component_type' => 'deduction', 'amount' => 200, 'percentage' => null, 'is_taxable' => false, 'sort_order' => 5],
        ]);

        return $template->fresh('components');
    }

    private function seedAdminTemplate(int $userId): SalaryTemplate
    {
        $template = SalaryTemplate::query()->firstOrCreate(
            ['name' => 'Demo Non-Teaching Payroll Template'],
            [
                'description' => 'Monthly non-teaching payroll template.',
                'is_active' => true,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]
        );

        $template->components()->delete();
        $template->components()->createMany([
            ['component_name' => 'Basic', 'component_type' => 'earning', 'amount' => 22000, 'percentage' => null, 'is_taxable' => true, 'sort_order' => 1],
            ['component_name' => 'Allowance', 'component_type' => 'earning', 'amount' => 5000, 'percentage' => null, 'is_taxable' => true, 'sort_order' => 2],
            ['component_name' => 'PF', 'component_type' => 'deduction', 'amount' => 1800, 'percentage' => null, 'is_taxable' => false, 'sort_order' => 3],
            ['component_name' => 'ESI', 'component_type' => 'deduction', 'amount' => 250, 'percentage' => null, 'is_taxable' => false, 'sort_order' => 4],
        ]);

        return $template->fresh('components');
    }

    /**
     * @param  array<int, Staff>  $staffRows
     */
    private function seedSalaryStructures(array $staffRows, int $teachingTemplateId, int $adminTemplateId, int $userId): void
    {
        $effectiveFrom = Carbon::now()->startOfYear()->toDateString();

        foreach ($staffRows as $staff) {
            StaffSalaryStructure::query()->updateOrCreate(
                [
                    'staff_id' => $staff->id,
                    'effective_from' => $effectiveFrom,
                ],
                [
                    'salary_template_id' => $staff->employee_type === 'teaching' ? $teachingTemplateId : $adminTemplateId,
                    'effective_to' => null,
                    'status' => 'active',
                    'notes' => 'Seeded payroll structure',
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]
            );
        }
    }

    private function seedAttendanceMonthLock(Carbon $period, int $adminUserId): void
    {
        StaffAttendanceMonthLock::query()->updateOrCreate(
            ['year' => (int) $period->year, 'month' => (int) $period->month],
            [
                'is_locked' => true,
                'locked_at' => now()->subDays(20),
                'locked_by' => $adminUserId,
                'unlocked_at' => null,
                'unlocked_by' => null,
                'override_reason' => null,
            ]
        );
    }

    /**
     * @param  array<int, Staff>  $staffRows
     */
    private function seedAttendanceRecords(array $staffRows, Carbon $period, int $adminUserId): void
    {
        $daysToSeed = min(15, $period->daysInMonth);

        foreach ($staffRows as $staff) {
            for ($day = 1; $day <= $daysToSeed; $day++) {
                $attendanceDate = $period->copy()->day($day)->toDateString();
                $status = match (true) {
                    $day % 7 === 0 => 'leave',
                    $day % 6 === 0 => 'half_day',
                    $day % 5 === 0 => 'absent',
                    default => 'present',
                };

                $payload = [
                    'status' => $status,
                    'late_minutes' => $status === 'present' ? ($day % 3) * 5 : null,
                    'remarks' => 'Seeded attendance entry',
                    'created_by' => $adminUserId,
                    'updated_by' => $adminUserId,
                    'override_reason' => null,
                ];

                if (Schema::hasColumn('staff_attendance_records', 'source')) {
                    $payload['source'] = 'manual';
                }
                if (Schema::hasColumn('staff_attendance_records', 'approval_status')) {
                    $payload['approval_status'] = 'approved';
                }
                if (Schema::hasColumn('staff_attendance_records', 'approved_by')) {
                    $payload['approved_by'] = $adminUserId;
                }
                if (Schema::hasColumn('staff_attendance_records', 'approved_at')) {
                    $payload['approved_at'] = now()->subDays(10);
                }

                StaffAttendanceRecord::query()->updateOrCreate(
                    [
                        'staff_id' => $staff->id,
                        'attendance_date' => $attendanceDate,
                    ],
                    $payload
                );
            }
        }
    }

    /**
     * @param  array<int, Staff>  $staffRows
     * @param  array<string, int>  $leaveTypes
     */
    private function seedLeavesAndLedger(array $staffRows, array $leaveTypes, Carbon $period, int $adminUserId): void
    {
        if (count($staffRows) < 2) {
            return;
        }

        $casualLeaveTypeId = $leaveTypes['Casual Leave'] ?? null;
        $sickLeaveTypeId = $leaveTypes['Sick Leave'] ?? null;
        if (!$casualLeaveTypeId || !$sickLeaveTypeId) {
            return;
        }

        $approvedStart = $period->copy()->day(7)->toDateString();
        $approvedEnd = $period->copy()->day(8)->toDateString();
        DB::table('staff_leaves')->updateOrInsert(
            [
                'staff_id' => $staffRows[0]->id,
                'leave_type_id' => $casualLeaveTypeId,
                'start_date' => $approvedStart,
                'end_date' => $approvedEnd,
            ],
            [
                'total_days' => 2,
                'reason' => 'Seeded approved leave request',
                'status' => 'approved',
                'approved_by' => $adminUserId,
                'remarks' => 'Approved by seeder',
                'created_at' => now()->subDays(12),
                'updated_at' => now()->subDays(11),
            ]
        );
        $approvedLeaveId = (int) DB::table('staff_leaves')
            ->where('staff_id', $staffRows[0]->id)
            ->where('leave_type_id', $casualLeaveTypeId)
            ->whereDate('start_date', $approvedStart)
            ->whereDate('end_date', $approvedEnd)
            ->value('id');

        DB::table('staff_leaves')->updateOrInsert(
            [
                'staff_id' => $staffRows[1]->id,
                'leave_type_id' => $sickLeaveTypeId,
                'start_date' => Carbon::now()->addDays(2)->toDateString(),
                'end_date' => Carbon::now()->addDays(3)->toDateString(),
            ],
            [
                'total_days' => 2,
                'reason' => 'Seeded pending leave request',
                'status' => 'pending',
                'approved_by' => null,
                'remarks' => null,
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ]
        );

        LeaveLedgerEntry::query()->updateOrCreate(
            [
                'staff_id' => $staffRows[0]->id,
                'leave_type_id' => $casualLeaveTypeId,
                'entry_type' => 'credit',
                'entry_date' => $period->copy()->startOfMonth()->toDateString(),
                'reference_type' => 'policy_seed',
            ],
            [
                'quantity' => 12,
                'reference_id' => null,
                'remarks' => 'Annual casual leave credit (seeded)',
                'created_by' => $adminUserId,
            ]
        );

        LeaveLedgerEntry::query()->updateOrCreate(
            [
                'staff_id' => $staffRows[0]->id,
                'leave_type_id' => $casualLeaveTypeId,
                'entry_type' => 'debit',
                'reference_type' => 'staff_leave',
                'reference_id' => $approvedLeaveId,
            ],
            [
                'quantity' => 2,
                'entry_date' => $period->copy()->day(7)->toDateString(),
                'remarks' => 'Approved leave debit (seeded)',
                'created_by' => $adminUserId,
            ]
        );
    }

    /**
     * @param  array<int, Staff>  $staffRows
     */
    private function seedPayrollSnapshot(array $staffRows, Carbon $period, int $accountantUserId): void
    {
        $batch = PayrollBatch::query()->updateOrCreate(
            ['year' => (int) $period->year, 'month' => (int) $period->month],
            [
                'period_start' => $period->copy()->startOfMonth()->toDateString(),
                'period_end' => $period->copy()->endOfMonth()->toDateString(),
                'status' => 'paid',
                'is_locked' => true,
                'generated_at' => now()->subDays(20),
                'generated_by' => $accountantUserId,
                'finalized_at' => now()->subDays(18),
                'finalized_by' => $accountantUserId,
                'paid_at' => now()->subDays(15),
                'paid_by' => $accountantUserId,
                'journal_entry_id' => null,
            ]
        );

        $existingItemIds = $batch->items()->pluck('id');
        if ($existingItemIds->isNotEmpty()) {
            PayrollItemAdjustment::query()->whereIn('payroll_batch_item_id', $existingItemIds)->delete();
            PayrollBatchItem::query()->whereIn('id', $existingItemIds)->delete();
        }

        foreach ($staffRows as $index => $staff) {
            $grossPay = (float) ($staff->salary ?? 30000);
            $totalDeductions = round($grossPay * 0.08, 2);
            $netPay = round($grossPay - $totalDeductions, 2);

            $salaryStructureId = (int) StaffSalaryStructure::query()
                ->where('staff_id', $staff->id)
                ->orderByDesc('effective_from')
                ->value('id');

            $item = PayrollBatchItem::query()->create([
                'payroll_batch_id' => $batch->id,
                'staff_id' => $staff->id,
                'staff_salary_structure_id' => $salaryStructureId ?: null,
                'days_in_month' => (int) $period->daysInMonth,
                'payable_days' => (float) ($period->daysInMonth - 2),
                'leave_days' => 1,
                'absent_days' => 1,
                'gross_pay' => $grossPay,
                'total_deductions' => $totalDeductions,
                'net_pay' => $netPay,
                'snapshot' => [
                    'source' => 'HrPayrollDemoSeeder',
                    'staff_id' => $staff->id,
                    'employee_id' => $staff->employee_id,
                    'components' => [
                        ['name' => 'Gross', 'type' => 'earning', 'amount' => $grossPay],
                        ['name' => 'Total Deductions', 'type' => 'deduction', 'amount' => $totalDeductions],
                    ],
                    'computed' => [
                        'net' => $netPay,
                    ],
                ],
            ]);

            if ($index === 0) {
                PayrollItemAdjustment::query()->create([
                    'payroll_batch_item_id' => $item->id,
                    'adjustment_type' => 'recovery',
                    'amount' => 500,
                    'remarks' => 'Seeded overpayment recovery adjustment',
                    'created_by' => $accountantUserId,
                ]);
            }
        }
    }
}
