<?php

namespace App\Http\Controllers\Api\Transport;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\StudentTransportAssignment;
use App\Models\TransportStop;
use App\Models\TransportFeeCycle;
use App\Services\Accounting\AccountingService;
use App\Services\Email\EventNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TransportAssignmentController extends Controller
{
    public function index(Request $request)
    {
        $query = StudentTransportAssignment::with(['route', 'stop'])
            ->orderByDesc('id');

        if ($request->filled('enrollment_id')) {
            $query->where('enrollment_id', $request->integer('enrollment_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json(
            $query->paginate($request->integer('per_page', 15))
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'enrollment_id' => 'required|exists:enrollments,id',
            'route_id' => 'required|exists:transport_routes,id',
            'stop_id' => 'required|exists:transport_stops,id',
            'start_date' => 'required|date',
            // When true, automatically creates the first transport fee cycle + ledger debit
            // for the month/year of start_date (unless charge_month/charge_year provided).
            'auto_generate_cycle' => 'sometimes|boolean',
            'charge_month' => 'sometimes|integer|min:1|max:12',
            'charge_year' => 'sometimes|integer|min:2000|max:2100',
            'charge_amount' => 'nullable|numeric|min:0.01',
        ]);

        $autoGenerateCycle = (bool) ($data['auto_generate_cycle'] ?? false);

        return DB::transaction(function () use ($data, $autoGenerateCycle) {
            $enrollment = Enrollment::findOrFail($data['enrollment_id']);

            if ($enrollment->status !== 'active' || $enrollment->is_locked) {
                return response()->json([
                    'message' => 'Only active, unlocked enrollments can receive transport assignments.'
                ], 422);
            }

            $existingActive = StudentTransportAssignment::where('enrollment_id', $enrollment->id)
                ->where('status', 'active')
                ->whereNull('end_date')
                ->first();

            if ($existingActive) {
                return response()->json([
                    'message' => 'Transport is already assigned for this enrollment. Stop the existing assignment first.',
                    'assignment_id' => (int) $existingActive->id,
                ], 409);
            }

            $stop = TransportStop::where('id', $data['stop_id'])
                ->where('route_id', $data['route_id'])
                ->first();

            if (!$stop) {
                return response()->json([
                    'message' => 'Stop does not belong to the specified route'
                ], 422);
            }

            $assignment = StudentTransportAssignment::create([
                'enrollment_id' => $data['enrollment_id'],
                'route_id' => $data['route_id'],
                'stop_id' => $data['stop_id'],
                'start_date' => $data['start_date'],
                'status' => 'active',
                'assigned_by' => Auth::id(),
            ]);

            AuditLog::log('create', $assignment, null, $assignment->toArray(), 'Transport assignment created');

            if ($autoGenerateCycle) {
                $start = \Carbon\Carbon::parse($data['start_date']);
                $month = (int) ($data['charge_month'] ?? $start->format('n'));
                $year = (int) ($data['charge_year'] ?? $start->format('Y'));

                $existingCycle = TransportFeeCycle::where('assignment_id', $assignment->id)
                    ->where('month', $month)
                    ->where('year', $year)
                    ->first();

                if (!$existingCycle) {
                    $amount = isset($data['charge_amount'])
                        ? (string) $data['charge_amount']
                        : (string) ($stop->fee_amount ?? 0);

                    if ((float) $amount > 0) {
                        $cycle = TransportFeeCycle::create([
                            'assignment_id' => $assignment->id,
                            'month' => $month,
                            'year' => $year,
                            'amount' => $amount,
                            'generated_at' => now(),
                        ]);

                        $accounting = app(AccountingService::class);
                        $posted = $accounting->postTransportCharge($enrollment, (int) $cycle->id, (float) $amount, now());
                        $ledger = $posted['student_fee_ledger'];

                        AuditLog::log('create', $cycle, null, $cycle->toArray(), 'Transport fee cycle auto-generated on assignment');
                        AuditLog::log('create', $ledger, null, $ledger->toArray(), 'Ledger debit (projection) created from auto-generated transport fee cycle');

                        DB::afterCommit(function () use ($ledger) {
                            app(EventNotificationService::class)->notifyStudentLedgerRecorded(
                                $ledger->fresh(['enrollment.student.user', 'enrollment.student.profile', 'enrollment.student.parents.user', 'enrollment.section.class', 'enrollment.classModel', 'enrollment.academicYear']),
                                'Transport charge added',
                                'A transport charge has been added to the student account.'
                            );
                        });
                    }
                }
            }

            return response()->json($assignment->load(['route', 'stop']), 201);
        });
    }

    public function bulkStore(Request $request)
    {
        $data = $request->validate([
            'enrollment_ids' => 'required|array|min:1|max:200',
            'enrollment_ids.*' => 'integer|distinct|exists:enrollments,id',
            'route_id' => 'required|exists:transport_routes,id',
            'stop_id' => 'required|exists:transport_stops,id',
            'start_date' => 'required|date',
            'auto_generate_cycle' => 'sometimes|boolean',
            'charge_month' => 'sometimes|integer|min:1|max:12',
            'charge_year' => 'sometimes|integer|min:2000|max:2100',
            'charge_amount' => 'nullable|numeric|min:0.01',
        ]);

        $autoGenerateCycle = (bool) ($data['auto_generate_cycle'] ?? false);

        $stop = TransportStop::where('id', $data['stop_id'])
            ->where('route_id', $data['route_id'])
            ->first();

        if (!$stop) {
            return response()->json([
                'message' => 'Stop does not belong to the specified route'
            ], 422);
        }

        return DB::transaction(function () use ($data, $autoGenerateCycle, $stop) {
            $start = \Carbon\Carbon::parse($data['start_date']);
            $month = (int) ($data['charge_month'] ?? $start->format('n'));
            $year = (int) ($data['charge_year'] ?? $start->format('Y'));
            $amount = isset($data['charge_amount'])
                ? (string) $data['charge_amount']
                : (string) ($stop->fee_amount ?? 0);

            $results = [];
            $createdCount = 0;
            $chargedCount = 0;

            foreach ($data['enrollment_ids'] as $enrollmentId) {
                $enrollment = Enrollment::find($enrollmentId);
                if (!$enrollment) {
                    $results[] = [
                        'enrollment_id' => (int) $enrollmentId,
                        'status' => 'skipped',
                        'message' => 'Enrollment not found.',
                    ];
                    continue;
                }

                if ($enrollment->status !== 'active' || $enrollment->is_locked) {
                    $results[] = [
                        'enrollment_id' => (int) $enrollment->id,
                        'status' => 'skipped',
                        'message' => 'Enrollment must be active and unlocked.',
                    ];
                    continue;
                }

                $existingActive = StudentTransportAssignment::where('enrollment_id', $enrollment->id)
                    ->where('status', 'active')
                    ->whereNull('end_date')
                    ->first();

                if ($existingActive) {
                    $results[] = [
                        'enrollment_id' => (int) $enrollment->id,
                        'status' => 'skipped',
                        'message' => 'Transport already assigned.',
                        'assignment_id' => (int) $existingActive->id,
                    ];
                    continue;
                }

                $assignment = StudentTransportAssignment::create([
                    'enrollment_id' => $enrollment->id,
                    'route_id' => $data['route_id'],
                    'stop_id' => $data['stop_id'],
                    'start_date' => $data['start_date'],
                    'status' => 'active',
                    'assigned_by' => Auth::id(),
                ]);

                $createdCount++;

                AuditLog::log('create', $assignment, null, $assignment->toArray(), 'Transport assignment created (bulk)');

                if ($autoGenerateCycle && (float) $amount > 0) {
                    $cycle = TransportFeeCycle::create([
                        'assignment_id' => $assignment->id,
                        'month' => $month,
                        'year' => $year,
                        'amount' => $amount,
                        'generated_at' => now(),
                    ]);

                    $accounting = app(AccountingService::class);
                    $posted = $accounting->postTransportCharge($enrollment, (int) $cycle->id, (float) $amount, now());
                    $ledger = $posted['student_fee_ledger'];

                    $chargedCount++;
                    AuditLog::log('create', $cycle, null, $cycle->toArray(), 'Transport fee cycle generated (bulk)');
                    AuditLog::log('create', $ledger, null, $ledger->toArray(), 'Ledger debit (projection) created from transport fee cycle (bulk)');

                    DB::afterCommit(function () use ($ledger) {
                        app(EventNotificationService::class)->notifyStudentLedgerRecorded(
                            $ledger->fresh(['enrollment.student.user', 'enrollment.student.profile', 'enrollment.student.parents.user', 'enrollment.section.class', 'enrollment.classModel', 'enrollment.academicYear']),
                            'Transport charge added',
                            'A transport charge has been added to the student account.'
                        );
                    });
                }

                $results[] = [
                    'enrollment_id' => (int) $enrollment->id,
                    'status' => 'created',
                    'assignment_id' => (int) $assignment->id,
                ];
            }

            return response()->json([
                'message' => 'Bulk transport assignment processed.',
                'created_count' => $createdCount,
                'charged_count' => $chargedCount,
                'results' => $results,
            ], 200);
        });
    }

    public function stop(Request $request, $id)
    {
        $data = $request->validate([
            'end_date' => 'required|date',
        ]);

        $assignment = StudentTransportAssignment::findOrFail($id);
        $oldValues = $assignment->toArray();

        if ($assignment->status !== 'active') {
            return response()->json([
                'message' => 'Assignment is already stopped.'
            ], 409);
        }

        $assignment->update([
            'end_date' => $data['end_date'],
            'status' => 'stopped',
        ]);

        AuditLog::log('update', $assignment, $oldValues, $assignment->toArray(), 'Transport assignment stopped');

        return response()->json($assignment->load(['route', 'stop']));
    }
}
