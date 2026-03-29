<?php

namespace App\Http\Controllers\Api\Transport;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\StudentTransportAssignment;
use App\Models\TransportFeeCycle;
use App\Services\Accounting\AccountingService;
use App\Services\Email\EventNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransportFeeCycleController extends Controller
{
    public function generate(Request $request)
    {
        $data = $request->validate([
            'assignment_id' => 'required|exists:student_transport_assignments,id',
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2000|max:2100',
            'amount' => 'nullable|numeric|min:0.01',
        ]);

        $existing = TransportFeeCycle::where('assignment_id', $data['assignment_id'])
            ->where('month', $data['month'])
            ->where('year', $data['year'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Fee cycle already exists for this month.'
            ], 409);
        }

        return DB::transaction(function () use ($data) {
            $assignment = StudentTransportAssignment::findOrFail($data['assignment_id']);
            $assignment->loadMissing(['route', 'stop']);
            if ($assignment->status !== 'active') {
                return response()->json([
                    'message' => 'Only active assignments can be charged.'
                ], 422);
            }

            $amount = isset($data['amount'])
                ? (string) $data['amount']
                : (string) ($assignment->stop?->fee_amount ?? $assignment->route?->fee_amount ?? 0);
            if ((float) $amount <= 0) {
                return response()->json([
                    'message' => 'Amount is required (or set a fee_amount on the route).'
                ], 422);
            }

            $cycle = TransportFeeCycle::create([
                'assignment_id' => $assignment->id,
                'month' => $data['month'],
                'year' => $data['year'],
                'amount' => $amount,
                'generated_at' => now(),
            ]);

            $enrollment = $assignment->enrollment()->firstOrFail();

            $accounting = app(AccountingService::class);
            $posted = $accounting->postTransportCharge($enrollment, (int) $cycle->id, (float) $amount, now());
            $ledger = $posted['student_fee_ledger'];

            AuditLog::log('create', $cycle, null, $cycle->toArray(), 'Transport fee cycle generated');
            AuditLog::log('create', $ledger, null, $ledger->toArray(), 'Ledger debit (projection) created from transport fee cycle');

            DB::afterCommit(function () use ($ledger) {
                app(EventNotificationService::class)->notifyStudentLedgerRecorded(
                    $ledger->fresh(['enrollment.student.user', 'enrollment.student.profile', 'enrollment.student.parents.user', 'enrollment.section.class', 'enrollment.classModel', 'enrollment.academicYear']),
                    'Transport charge added',
                    'A transport charge has been added to the student account.'
                );
            });

            return response()->json([
                'cycle' => $cycle,
                'ledger_entry' => $ledger,
                'journal_entry' => $posted['journal_entry'],
            ], 201);
        });
    }
}
