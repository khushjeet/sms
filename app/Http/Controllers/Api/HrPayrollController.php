<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LeaveLedgerEntry;
use App\Models\PayrollBatch;
use App\Models\PayrollBatchItem;
use App\Models\PayrollItemAdjustment;
use App\Models\SalaryTemplate;
use App\Models\Staff;
use App\Models\StaffAttendanceApprovalLog;
use App\Models\StaffAttendanceMonthLock;
use App\Models\StaffAttendancePunchEvent;
use App\Models\StaffAttendanceRecord;
use App\Models\StaffAttendanceSession;
use App\Models\StaffSalaryStructure;
use App\Services\InAppNotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class HrPayrollController extends Controller
{
    public function leaveTypes()
    {
        return response()->json(
            DB::table('leave_types')
                ->orderBy('name')
                ->get()
        );
    }

    public function leaveRequests(Request $request)
    {
        $validated = $request->validate([
            'status' => 'nullable|in:pending,approved,rejected',
            'staff_id' => 'nullable|exists:staff,id',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $query = DB::table('staff_leaves')
            ->leftJoin('staff', 'staff.id', '=', 'staff_leaves.staff_id')
            ->select(
                'staff_leaves.*',
                'staff.employee_id as employee_code'
            )
            ->orderByDesc('staff_leaves.id');

        if (!empty($validated['status'])) {
            $query->where('staff_leaves.status', $validated['status']);
        }
        if (!empty($validated['staff_id'])) {
            $query->where('staff_leaves.staff_id', (int) $validated['staff_id']);
        }

        return response()->json($query->paginate((int) ($validated['per_page'] ?? 25)));
    }

    public function markAttendance(Request $request)
    {
        $validated = $request->validate([
            'staff_id' => 'required|exists:staff,id',
            'date' => 'required|date',
            'status' => 'required|in:present,absent,half_day,leave',
            'late_minutes' => 'nullable|integer|min:0|max:1440',
            'remarks' => 'nullable|string',
            'override_locked_month' => 'nullable|boolean',
            'override_reason' => 'nullable|string|max:1000',
        ]);

        $staffId = (int) $validated['staff_id'];
        if (!$this->canMarkAttendanceForStaff($request, $staffId)) {
            return response()->json([
                'message' => 'Not allowed to mark attendance for this employee.',
            ], 403);
        }

        $attendanceDate = Carbon::parse($validated['date']);
        $attendanceDateString = $attendanceDate->toDateString();
        $monthLock = StaffAttendanceMonthLock::query()
            ->where('year', (int) $attendanceDate->year)
            ->where('month', (int) $attendanceDate->month)
            ->first();

        $override = (bool) ($validated['override_locked_month'] ?? false);
        if ($monthLock?->is_locked && !$override) {
            return response()->json([
                'message' => 'Attendance is locked for this month. Use admin override.',
            ], 422);
        }

        if ($monthLock?->is_locked && $override) {
            if (!$this->canOverride($request)) {
                return response()->json(['message' => 'Not allowed to override locked attendance.'], 403);
            }
            if (empty($validated['override_reason'])) {
                return response()->json(['message' => 'override_reason is required for locked month overrides.'], 422);
            }
        }

        $record = DB::transaction(function () use ($request, $validated, $override, $staffId, $attendanceDateString) {
            /** @var StaffAttendanceRecord|null $existing */
            $existing = StaffAttendanceRecord::query()
                ->where('staff_id', $staffId)
                ->whereDate('attendance_date', $attendanceDateString)
                ->first();

            $oldValues = $existing?->toArray();

            $record = StaffAttendanceRecord::updateOrCreate(
                [
                    'staff_id' => $staffId,
                    'attendance_date' => $attendanceDateString,
                ],
                [
                    'status' => $validated['status'],
                    'late_minutes' => $validated['late_minutes'] ?? null,
                    'remarks' => $validated['remarks'] ?? null,
                    'created_by' => $existing?->created_by ?? $request->user()?->id,
                    'updated_by' => $request->user()?->id,
                    'override_reason' => $override ? ($validated['override_reason'] ?? null) : null,
                ]
            );

            if ($override) {
                AuditLog::log(
                    'attendance.override',
                    $record,
                    $oldValues,
                    $record->toArray(),
                    $validated['override_reason'] ?? null
                );
            }

            return $record;
        });

        return response()->json([
            'message' => 'Attendance saved successfully.',
            'data' => $record,
        ]);
    }

    public function lockAttendanceMonth(Request $request)
    {
        $validated = $request->validate([
            'year' => 'required|integer|min:2000|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $lock = StaffAttendanceMonthLock::query()->updateOrCreate(
            ['year' => (int) $validated['year'], 'month' => (int) $validated['month']],
            [
                'is_locked' => true,
                'locked_at' => now(),
                'locked_by' => $request->user()?->id,
                'unlocked_at' => null,
                'unlocked_by' => null,
                'override_reason' => null,
            ]
        );

        return response()->json([
            'message' => 'Attendance month locked.',
            'data' => $lock,
        ]);
    }

    public function unlockAttendanceMonth(Request $request)
    {
        if (!$this->canOverride($request)) {
            return response()->json(['message' => 'Not allowed to unlock attendance month.'], 403);
        }

        $validated = $request->validate([
            'year' => 'required|integer|min:2000|max:2100',
            'month' => 'required|integer|min:1|max:12',
            'override_reason' => 'required|string|max:1000',
        ]);

        $lock = StaffAttendanceMonthLock::query()->where([
            'year' => (int) $validated['year'],
            'month' => (int) $validated['month'],
        ])->firstOrFail();

        $oldValues = $lock->toArray();
        $lock->update([
            'is_locked' => false,
            'unlocked_at' => now(),
            'unlocked_by' => $request->user()?->id,
            'override_reason' => $validated['override_reason'],
        ]);

        AuditLog::log(
            'attendance.unlock_override',
            $lock,
            $oldValues,
            $lock->fresh()->toArray(),
            $validated['override_reason']
        );

        return response()->json([
            'message' => 'Attendance month unlocked with admin override.',
            'data' => $lock->fresh(),
        ]);
    }

    public function dailySelfieAttendance(Request $request)
    {
        $validated = $request->validate([
            'date' => 'nullable|date',
            'status' => 'nullable|in:pending,approved,rejected',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $date = !empty($validated['date'])
            ? Carbon::parse($validated['date'])->toDateString()
            : now()->toDateString();
        $perPage = (int) ($validated['per_page'] ?? 50);

        $query = StaffAttendanceSession::query()
            ->with([
                'staff.user:id,first_name,last_name,email',
                'punchEvents' => function ($q) {
                    $q->orderByDesc('punched_at');
                },
            ])
            ->whereDate('attendance_date', $date)
            ->orderByDesc('id');

        if (!empty($validated['status'])) {
            $query->where('review_status', $validated['status']);
        }

        $rows = $query->paginate($perPage);
        $rows->getCollection()->transform(function (StaffAttendanceSession $session) {
            $events = $session->punchEvents->map(function (StaffAttendancePunchEvent $event) {
                return [
                    'id' => (int) $event->id,
                    'punch_type' => $event->punch_type,
                    'punched_at' => optional($event->punched_at)?->toIso8601String(),
                    'latitude' => $event->latitude !== null ? (float) $event->latitude : null,
                    'longitude' => $event->longitude !== null ? (float) $event->longitude : null,
                    'location_accuracy_meters' => $event->location_accuracy_meters,
                    'selfie_url' => $this->selfieUrl($event->selfie_path),
                    'source' => $event->source,
                ];
            })->values();

            return [
                'id' => (int) $session->id,
                'staff_id' => (int) $session->staff_id,
                'employee_id' => (string) ($session->staff?->employee_id ?? ''),
                'staff_name' => trim((string) (($session->staff?->user?->first_name ?? '') . ' ' . ($session->staff?->user?->last_name ?? ''))),
                'attendance_date' => optional($session->attendance_date)?->toDateString(),
                'punch_in_at' => optional($session->punch_in_at)?->toIso8601String(),
                'punch_out_at' => optional($session->punch_out_at)?->toIso8601String(),
                'duration_minutes' => $session->duration_minutes,
                'review_status' => $session->review_status,
                'punch_in_selfie_url' => $this->selfieUrl($session->punch_in_selfie_path),
                'punch_out_selfie_url' => $this->selfieUrl($session->punch_out_selfie_path),
                'events' => $events,
            ];
        });

        return response()->json($rows);
    }

    public function approveSelfieAttendance(Request $request, int $sessionId)
    {
        if (!$this->canOverride($request)) {
            return response()->json(['message' => 'Not allowed to approve attendance.'], 403);
        }

        $validated = $request->validate([
            'review_note' => 'nullable|string|max:1000',
        ]);

        $session = StaffAttendanceSession::query()->findOrFail($sessionId);
        $fromStatus = $session->review_status;

        DB::transaction(function () use ($request, $session, $fromStatus, $validated): void {
            $session->update([
                'review_status' => 'approved',
                'reviewed_by' => $request->user()?->id,
                'reviewed_at' => now(),
                'review_note' => $validated['review_note'] ?? null,
            ]);

            StaffAttendanceApprovalLog::query()->create([
                'staff_attendance_session_id' => $session->id,
                'from_status' => $fromStatus,
                'to_status' => 'approved',
                'action' => 'approved',
                'acted_by' => $request->user()?->id,
                'acted_at' => now(),
                'remarks' => $validated['review_note'] ?? null,
            ]);

            StaffAttendanceRecord::query()
                ->where('staff_attendance_session_id', $session->id)
                ->update([
                    'approval_status' => 'approved',
                    'approved_by' => $request->user()?->id,
                    'approved_at' => now(),
                    'updated_by' => $request->user()?->id,
                ]);
        });

        return response()->json([
            'message' => 'Attendance approved successfully.',
            'data' => $session->fresh(),
        ]);
    }

    public function createLeaveRequest(Request $request)
    {
        $validated = $request->validate([
            'staff_id' => 'required|exists:staff,id',
            'leave_type_id' => 'required|exists:leave_types,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'required|string|max:2000',
        ]);

        $start = Carbon::parse($validated['start_date']);
        $end = Carbon::parse($validated['end_date']);
        $totalDays = $start->diffInDays($end) + 1;

        $leaveId = DB::table('staff_leaves')->insertGetId([
            'staff_id' => (int) $validated['staff_id'],
            'leave_type_id' => (int) $validated['leave_type_id'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'total_days' => $totalDays,
            'reason' => $validated['reason'],
            'status' => 'pending',
            'approved_by' => null,
            'remarks' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(InAppNotificationService::class)->notifyLeaveSubmitted($leaveId);

        return response()->json([
            'message' => 'Leave request submitted.',
            'data' => DB::table('staff_leaves')->where('id', $leaveId)->first(),
        ], 201);
    }

    public function decideLeaveRequest(Request $request, int $leaveId)
    {
        $validated = $request->validate([
            'status' => 'required|in:approved,rejected',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $leave = DB::table('staff_leaves')->where('id', $leaveId)->first();
        if (!$leave) {
            abort(404, 'Leave request not found.');
        }

        DB::transaction(function () use ($request, $validated, $leave) {
            DB::table('staff_leaves')
                ->where('id', $leave->id)
                ->update([
                    'status' => $validated['status'],
                    'approved_by' => $request->user()?->id,
                    'remarks' => $validated['remarks'] ?? null,
                    'updated_at' => now(),
                ]);

            if ($validated['status'] !== 'approved') {
                return;
            }

            $alreadyPosted = LeaveLedgerEntry::query()
                ->where('reference_type', 'staff_leave')
                ->where('reference_id', (int) $leave->id)
                ->where('entry_type', 'debit')
                ->exists();
            if ($alreadyPosted) {
                return;
            }

            LeaveLedgerEntry::create([
                'staff_id' => (int) $leave->staff_id,
                'leave_type_id' => (int) $leave->leave_type_id,
                'entry_type' => 'debit',
                'quantity' => (float) $leave->total_days,
                'entry_date' => Carbon::parse((string) $leave->start_date)->toDateString(),
                'reference_type' => 'staff_leave',
                'reference_id' => (int) $leave->id,
                'remarks' => $validated['remarks'] ?? 'Leave approved',
                'created_by' => $request->user()?->id,
            ]);
        });

        app(InAppNotificationService::class)->notifyLeaveDecision($leaveId);

        return response()->json([
            'message' => 'Leave request updated.',
            'data' => DB::table('staff_leaves')->where('id', $leaveId)->first(),
        ]);
    }

    public function postLeaveLedgerEntry(Request $request)
    {
        $validated = $request->validate([
            'staff_id' => 'required|exists:staff,id',
            'leave_type_id' => 'nullable|exists:leave_types,id',
            'entry_type' => 'required|in:credit,adjustment',
            'quantity' => 'required|numeric|not_in:0',
            'entry_date' => 'required|date',
            'remarks' => 'nullable|string|max:1000',
        ]);

        if ($validated['entry_type'] === 'credit' && (float) $validated['quantity'] < 0) {
            return response()->json(['message' => 'Credit quantity cannot be negative.'], 422);
        }

        $entry = LeaveLedgerEntry::create([
            'staff_id' => (int) $validated['staff_id'],
            'leave_type_id' => $validated['leave_type_id'] ?? null,
            'entry_type' => $validated['entry_type'],
            'quantity' => (float) $validated['quantity'],
            'entry_date' => $validated['entry_date'],
            'reference_type' => 'manual',
            'reference_id' => null,
            'remarks' => $validated['remarks'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Leave ledger entry posted.',
            'data' => $entry,
        ], 201);
    }

    public function leaveBalance(Request $request, int $staffId)
    {
        Staff::query()->findOrFail($staffId);

        $validated = $request->validate([
            'leave_type_id' => 'nullable|exists:leave_types,id',
        ]);

        $query = LeaveLedgerEntry::query()->where('staff_id', $staffId);
        if (!empty($validated['leave_type_id'])) {
            $query->where('leave_type_id', (int) $validated['leave_type_id']);
        }

        $rows = $query->get();
        $credits = (float) $rows->where('entry_type', 'credit')->sum('quantity');
        $debits = (float) $rows->where('entry_type', 'debit')->sum('quantity');
        $adjustments = (float) $rows->where('entry_type', 'adjustment')->sum('quantity');

        return response()->json([
            'staff_id' => $staffId,
            'leave_type_id' => $validated['leave_type_id'] ?? null,
            'credits' => round($credits, 2),
            'debits' => round($debits, 2),
            'adjustments' => round($adjustments, 2),
            'balance' => round($credits - $debits + $adjustments, 2),
        ]);
    }

    public function createSalaryTemplate(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:salary_templates,name',
            'description' => 'nullable|string|max:2000',
            'is_active' => 'nullable|boolean',
            'components' => 'required|array|min:1',
            'components.*.component_name' => 'required|string|max:255',
            'components.*.component_type' => 'required|in:earning,deduction',
            'components.*.amount' => 'nullable|numeric|min:0',
            'components.*.percentage' => 'nullable|numeric|min:0|max:100',
            'components.*.is_taxable' => 'nullable|boolean',
            'components.*.sort_order' => 'nullable|integer|min:0|max:9999',
        ]);

        $template = DB::transaction(function () use ($request, $validated) {
            $template = SalaryTemplate::create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'is_active' => (bool) ($validated['is_active'] ?? true),
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
            ]);

            foreach ($validated['components'] as $component) {
                if (
                    empty($component['amount'])
                    && !array_key_exists('percentage', $component)
                ) {
                    abort(422, 'Each component must have amount or percentage.');
                }

                $template->components()->create([
                    'component_name' => $component['component_name'],
                    'component_type' => $component['component_type'],
                    'amount' => $component['amount'] ?? null,
                    'percentage' => $component['percentage'] ?? null,
                    'is_taxable' => (bool) ($component['is_taxable'] ?? false),
                    'sort_order' => (int) ($component['sort_order'] ?? 0),
                ]);
            }

            return $template->load('components');
        });

        return response()->json([
            'message' => 'Salary template created.',
            'data' => $template,
        ], 201);
    }

    public function listSalaryTemplates()
    {
        return response()->json(
            SalaryTemplate::query()
                ->with(['components' => function ($query) {
                    $query->orderBy('sort_order')->orderBy('id');
                }])
                ->orderByDesc('id')
                ->get()
        );
    }

    public function assignSalaryStructure(Request $request)
    {
        $validated = $request->validate([
            'staff_id' => 'required|exists:staff,id',
            'salary_template_id' => 'required|exists:salary_templates,id',
            'effective_from' => 'required|date',
            'notes' => 'nullable|string|max:2000',
        ]);

        $structure = DB::transaction(function () use ($request, $validated) {
            $effectiveFrom = Carbon::parse($validated['effective_from'])->toDateString();
            $staffId = (int) $validated['staff_id'];

            StaffSalaryStructure::query()
                ->where('staff_id', $staffId)
                ->where('status', 'active')
                ->whereDate('effective_from', '<=', $effectiveFrom)
                ->update([
                    'status' => 'inactive',
                    'effective_to' => Carbon::parse($effectiveFrom)->subDay()->toDateString(),
                    'updated_by' => $request->user()?->id,
                ]);

            return StaffSalaryStructure::create([
                'staff_id' => $staffId,
                'salary_template_id' => (int) $validated['salary_template_id'],
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
                'status' => 'active',
                'notes' => $validated['notes'] ?? null,
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
            ]);
        });

        return response()->json([
            'message' => 'Salary structure version assigned.',
            'data' => $structure->load('template.components'),
        ], 201);
    }

    public function generatePayroll(Request $request)
    {
        $validated = $request->validate([
            'year' => 'required|integer|min:2000|max:2100',
            'month' => 'required|integer|min:1|max:12',
            'force_regenerate' => 'nullable|boolean',
        ]);

        $year = (int) $validated['year'];
        $month = (int) $validated['month'];
        $forceRegenerate = (bool) ($validated['force_regenerate'] ?? false);

        $monthLock = StaffAttendanceMonthLock::query()
            ->where('year', $year)
            ->where('month', $month)
            ->where('is_locked', true)
            ->exists();
        if (!$monthLock) {
            return response()->json([
                'message' => 'Attendance month must be locked before payroll generation.',
            ], 422);
        }

        $periodStart = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();

        $batch = PayrollBatch::query()->where('year', $year)->where('month', $month)->first();
        if ($batch && in_array($batch->status, ['finalized', 'paid'], true)) {
            return response()->json([
                'message' => 'Payroll already finalized/paid. Create adjustments instead of regeneration.',
            ], 422);
        }
        if ($batch && !$forceRegenerate) {
            return response()->json([
                'message' => 'Payroll already generated for this month. Use force_regenerate=true to regenerate.',
            ], 422);
        }

        $batch = DB::transaction(function () use ($request, $batch, $year, $month, $periodStart, $periodEnd) {
            $batch = $batch ?: PayrollBatch::create([
                'year' => $year,
                'month' => $month,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
            ]);

            $batch->update([
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'status' => 'generated',
                'is_locked' => false,
                'generated_at' => now(),
                'generated_by' => $request->user()?->id,
                'finalized_at' => null,
                'finalized_by' => null,
                'paid_at' => null,
                'paid_by' => null,
                'journal_entry_id' => null,
            ]);

            $batch->items()->delete();

            $staffMembers = Staff::query()
                ->where('status', 'active')
                ->with(['salaryStructures.template.components'])
                ->get();

            foreach ($staffMembers as $staff) {
                $structure = StaffSalaryStructure::query()
                    ->where('staff_id', $staff->id)
                    ->whereDate('effective_from', '<=', $periodEnd->toDateString())
                    ->where(function ($query) use ($periodStart) {
                        $query->whereNull('effective_to')
                            ->orWhereDate('effective_to', '>=', $periodStart->toDateString());
                    })
                    ->orderByDesc('effective_from')
                    ->first();

                if (!$structure || !$structure->template) {
                    continue;
                }

                $attendance = StaffAttendanceRecord::query()
                    ->where('staff_id', $staff->id)
                    ->whereDate('attendance_date', '>=', $periodStart->toDateString())
                    ->whereDate('attendance_date', '<=', $periodEnd->toDateString())
                    ->get();

                $daysInMonth = (int) $periodStart->daysInMonth;
                $present = (float) $attendance->where('status', 'present')->count();
                $absent = (float) $attendance->where('status', 'absent')->count();
                $leave = (float) $attendance->where('status', 'leave')->count();
                $halfDay = (float) $attendance->where('status', 'half_day')->count();
                $unmarked = max(0.0, $daysInMonth - $attendance->count());
                $absentDays = $absent + $unmarked;
                $payableDays = $present + $leave + ($halfDay * 0.5);
                $ratio = $daysInMonth > 0 ? ($payableDays / $daysInMonth) : 0.0;

                [$gross, $deductions, $componentSnapshot] = $this->computeTemplateAmounts($structure->template);

                $proRatedGross = round($gross * $ratio, 2);
                $proRatedDeductions = round($deductions * $ratio, 2);
                $net = round($proRatedGross - $proRatedDeductions, 2);

                PayrollBatchItem::create([
                    'payroll_batch_id' => $batch->id,
                    'staff_id' => $staff->id,
                    'staff_salary_structure_id' => $structure->id,
                    'days_in_month' => $daysInMonth,
                    'payable_days' => round($payableDays, 2),
                    'leave_days' => round($leave, 2),
                    'absent_days' => round($absentDays, 2),
                    'gross_pay' => $proRatedGross,
                    'total_deductions' => $proRatedDeductions,
                    'net_pay' => $net,
                    'snapshot' => [
                        'staff_id' => $staff->id,
                        'employee_id' => $staff->employee_id,
                        'salary_structure_id' => $structure->id,
                        'salary_template_id' => $structure->salary_template_id,
                        'effective_from' => optional($structure->effective_from)->toDateString(),
                        'effective_to' => optional($structure->effective_to)->toDateString(),
                        'attendance' => [
                            'present' => $present,
                            'leave' => $leave,
                            'half_day' => $halfDay,
                            'absent' => $absent,
                            'unmarked' => $unmarked,
                            'days_in_month' => $daysInMonth,
                            'payable_days' => round($payableDays, 2),
                            'pay_ratio' => round($ratio, 4),
                        ],
                        'components' => $componentSnapshot,
                        'computed' => [
                            'full_month_gross' => round($gross, 2),
                            'full_month_deductions' => round($deductions, 2),
                            'pro_rated_gross' => $proRatedGross,
                            'pro_rated_deductions' => $proRatedDeductions,
                            'net' => $net,
                        ],
                    ],
                ]);
            }

            return $batch->fresh()->load('items');
        });

        return response()->json([
            'message' => 'Payroll generated.',
            'data' => $batch,
        ], 201);
    }

    public function finalizePayroll(Request $request, int $batchId)
    {
        $batch = PayrollBatch::query()->findOrFail($batchId);
        if ($batch->status !== 'generated') {
            return response()->json(['message' => 'Only generated payroll can be finalized.'], 422);
        }

        $batch->update([
            'status' => 'finalized',
            'is_locked' => true,
            'finalized_at' => now(),
            'finalized_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Payroll finalized and locked.',
            'data' => $batch->fresh(),
        ]);
    }

    public function markPayrollPaid(Request $request, int $batchId)
    {
        $batch = PayrollBatch::query()->findOrFail($batchId);
        if ($batch->status !== 'finalized') {
            return response()->json(['message' => 'Only finalized payroll can be marked paid.'], 422);
        }

        $batch->update([
            'status' => 'paid',
            'paid_at' => now(),
            'paid_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Payroll marked as paid.',
            'data' => $batch->fresh(),
        ]);
    }

    public function addPayrollAdjustment(Request $request, int $batchId, int $itemId)
    {
        $batch = PayrollBatch::query()->findOrFail($batchId);
        if (!in_array($batch->status, ['finalized', 'paid'], true)) {
            return response()->json([
                'message' => 'Adjustments are allowed only after payroll finalization.',
            ], 422);
        }

        $item = PayrollBatchItem::query()
            ->where('payroll_batch_id', $batch->id)
            ->where('id', $itemId)
            ->firstOrFail();

        $validated = $request->validate([
            'adjustment_type' => 'required|in:recovery,bonus,correction',
            'amount' => 'required|numeric|not_in:0',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $adjustment = PayrollItemAdjustment::create([
            'payroll_batch_item_id' => $item->id,
            'adjustment_type' => $validated['adjustment_type'],
            'amount' => round((float) $validated['amount'], 2),
            'remarks' => $validated['remarks'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        AuditLog::log(
            'payroll.adjustment',
            $item,
            null,
            $adjustment->toArray(),
            $validated['remarks'] ?? null
        );

        return response()->json([
            'message' => 'Payroll adjustment created. Finalized snapshot remains immutable.',
            'data' => $adjustment,
        ], 201);
    }

    public function showPayrollBatch(int $batchId)
    {
        $batch = PayrollBatch::query()
            ->with(['items.adjustments'])
            ->findOrFail($batchId);

        return response()->json($batch);
    }

    public function listPayrollBatches(Request $request)
    {
        $validated = $request->validate([
            'year' => 'nullable|integer|min:2000|max:2100',
            'month' => 'nullable|integer|min:1|max:12',
            'status' => 'nullable|in:generated,finalized,paid',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $query = PayrollBatch::query()->withCount('items')->orderByDesc('year')->orderByDesc('month');
        if (!empty($validated['year'])) {
            $query->where('year', (int) $validated['year']);
        }
        if (!empty($validated['month'])) {
            $query->where('month', (int) $validated['month']);
        }
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return response()->json($query->paginate((int) ($validated['per_page'] ?? 24)));
    }

    public function payrollPeriodOptions(Request $request)
    {
        $validated = $request->validate([
            'months_back' => 'nullable|integer|min:0|max:60',
            'months_forward' => 'nullable|integer|min:0|max:24',
        ]);

        $monthsBack = (int) ($validated['months_back'] ?? 24);
        $monthsForward = (int) ($validated['months_forward'] ?? 2);

        $start = Carbon::now()->startOfMonth()->subMonths($monthsBack);
        $end = Carbon::now()->startOfMonth()->addMonths($monthsForward);

        $minYear = (int) $start->year;
        $maxYear = (int) $end->year;

        $batches = PayrollBatch::query()
            ->select('id', 'year', 'month', 'status')
            ->whereBetween('year', [$minYear, $maxYear])
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get();

        $locks = StaffAttendanceMonthLock::query()
            ->select('year', 'month', 'is_locked')
            ->whereBetween('year', [$minYear, $maxYear])
            ->get();

        $batchMap = [];
        foreach ($batches as $batch) {
            $key = sprintf('%04d-%02d', $batch->year, $batch->month);
            $batchMap[$key] = [
                'id' => (int) $batch->id,
                'status' => $batch->status,
            ];
        }

        $lockMap = [];
        foreach ($locks as $lock) {
            $key = sprintf('%04d-%02d', $lock->year, $lock->month);
            $lockMap[$key] = (bool) $lock->is_locked;
        }

        $periods = [];
        $cursor = $start->copy();
        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->format('Y-m');
            $periods[] = [
                'value' => $key,
                'year' => (int) $cursor->year,
                'month' => (int) $cursor->month,
                'label' => $cursor->format('F Y'),
                'attendance_locked' => $lockMap[$key] ?? false,
                'payroll_status' => $batchMap[$key]['status'] ?? null,
                'payroll_batch_id' => $batchMap[$key]['id'] ?? null,
            ];
            $cursor->addMonth();
        }

        return response()->json(array_reverse($periods));
    }

    private function computeTemplateAmounts(SalaryTemplate $template): array
    {
        $components = $template->components()->orderBy('sort_order')->orderBy('id')->get();
        $fixedEarnings = 0.0;
        $snapshot = [];

        foreach ($components as $component) {
            if ($component->component_type === 'earning' && !is_null($component->amount)) {
                $fixedEarnings += (float) $component->amount;
            }
        }

        $gross = 0.0;
        $deductions = 0.0;

        foreach ($components as $component) {
            $amount = !is_null($component->amount)
                ? (float) $component->amount
                : round(($fixedEarnings * (float) ($component->percentage ?? 0)) / 100, 2);

            if ($component->component_type === 'earning') {
                $gross += $amount;
            } else {
                $deductions += $amount;
            }

            $snapshot[] = [
                'name' => $component->component_name,
                'type' => $component->component_type,
                'amount' => round($amount, 2),
                'source_amount' => $component->amount,
                'source_percentage' => $component->percentage,
            ];
        }

        return [round($gross, 2), round($deductions, 2), $snapshot];
    }

    private function canOverride(Request $request): bool
    {
        $user = $request->user();

        if (!$user) {
            return false;
        }

        return $user->hasRole(['super_admin', 'school_admin', 'hr', 'principal'])
            || $user->hasPermission('payroll.edit')
            || $user->hasPermission('attendance.override')
            || $user->hasPermission('system.manage');
    }

    private function canMarkAttendanceForStaff(Request $request, int $staffId): bool
    {
        $user = $request->user();
        if (!$user) {
            return false;
        }

        if (
            $user->hasRole(['super_admin', 'school_admin', 'hr'])
            || $user->hasPermission('attendance.approve')
            || $user->hasPermission('attendance.override')
            || $user->hasPermission('system.manage')
        ) {
            return true;
        }

        return (int) optional($user->staff)->id === $staffId;
    }

    private function selfieUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
