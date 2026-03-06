<?php

namespace App\Http\Controllers\Api\FeeFinance;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\FeeAssignment;
use App\Models\FeeStructure;
use App\Models\OptionalService;
use App\Models\StudentFeeLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class FeeAssignmentController extends Controller
{
    public function byEnrollment($id)
    {
        $enrollment = Enrollment::with([
            'student.user',
            'academicYear',
            'section.class',
            'feeAssignment',
            'optionalServices'
        ])->findOrFail($id);

        return response()->json($enrollment);
    }

    /**
     * Ledger-based financial summary
     */
    public function summary($id)
    {
        $enrollment = Enrollment::with(['feeAssignment'])
            ->findOrFail($id);

        $totals = StudentFeeLedger::where('enrollment_id', $enrollment->id)
            ->selectRaw("SUM(CASE WHEN transaction_type = 'debit' THEN amount ELSE 0 END) as debits")
            ->selectRaw("SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE 0 END) as credits")
            ->first();

        $debits = (float) ($totals->debits ?? 0);
        $credits = (float) ($totals->credits ?? 0);
        $balance = $debits - $credits;

        return response()->json([
            'enrollment_id' => $enrollment->id,
            'total_debits' => round($debits, 2),
            'total_credits' => round($credits, 2),
            'balance_due' => round($balance, 2),
        ]);
    }

    /**
     * Apply Discount (Ledger-backed)
     */
    public function applyDiscount(Request $request, $id)
    {
        $validated = $request->validate([
            'discount' => 'required|numeric|min:0.01',
            'discount_reason' => 'required|string',
        ]);

        return DB::transaction(function () use ($validated, $id) {
            $enrollment = Enrollment::with(['feeAssignment', 'academicYear'])
                ->lockForUpdate()
                ->findOrFail($id);

            if (!$enrollment->feeAssignment) {
                return response()->json([
                    'message' => 'Fee assignment not found.'
                ], 404);
            }

            $actorId = Auth::id();

            $ledger = StudentFeeLedger::create([
                'enrollment_id' => $enrollment->id,
                'transaction_type' => 'credit',
                'reference_type' => 'discount',
                'reference_id' => $enrollment->feeAssignment->id,
                'amount' => round((float) $validated['discount'], 2),
                'posted_by' => $actorId,
                'posted_at' => now(),
                'narration' => $validated['discount_reason'],
                'is_reversal' => false,
            ]);

            AuditLog::log(
                'create',
                $ledger,
                null,
                $ledger->toArray(),
                'Ledger credit created from discount'
            );

            return response()->json([
                'message' => 'Discount applied successfully',
                'ledger_entry' => $ledger,
                'assignment' => $enrollment->feeAssignment->fresh(),
            ]);
        });
    }

    public function assign(Request $request, $id)
    {
        $validated = $request->validate([
            'fee_structure_id' => 'required|exists:fee_structures,id',
            'optional_service_ids' => 'array',
            'optional_service_ids.*' => 'exists:optional_services,id',
            'additional_amount' => 'nullable|numeric|min:0',
        ]);

        return DB::transaction(function () use ($validated, $id) {
            $enrollment = Enrollment::with(['academicYear', 'optionalServices'])
                ->lockForUpdate()
                ->findOrFail($id);

            if ($enrollment->feeAssignment) {
                return response()->json([
                    'message' => 'Fee already assigned.'
                ], 400);
            }

            $feeStructure = FeeStructure::findOrFail($validated['fee_structure_id']);
            $baseAmount = (float) $feeStructure->amount + (float) ($validated['additional_amount'] ?? 0);

            $optionalServiceIds = collect($validated['optional_service_ids'] ?? [])
                ->map(fn ($v) => (int) $v)
                ->all();

            $optionalAmount = 0.0;
            if (!empty($optionalServiceIds)) {
                $optionalAmount = (float) OptionalService::whereIn('id', $optionalServiceIds)->sum('amount');
            }

            $totalFee = $baseAmount + $optionalAmount;

            $assignment = FeeAssignment::create([
                'enrollment_id' => $enrollment->id,
                'base_fee' => round($baseAmount, 2),
                'optional_services_fee' => round($optionalAmount, 2),
                'discount' => 0,
                'total_fee' => round($totalFee, 2),
            ]);

            if (!empty($optionalServiceIds)) {
                $enrollment->optionalServices()->sync($optionalServiceIds);
            }

            $ledger = StudentFeeLedger::create([
                'enrollment_id' => $enrollment->id,
                'transaction_type' => 'debit',
                'reference_type' => 'fee_assignment',
                'reference_id' => $assignment->id,
                'amount' => round($totalFee, 2),
                'posted_by' => Auth::id(),
                'posted_at' => now(),
                'is_reversal' => false,
            ]);

            AuditLog::log('create', $assignment, null, $assignment->toArray(), 'Fee assignment created');
            AuditLog::log('create', $ledger, null, $ledger->toArray(), 'Ledger debit created from fee assignment');

            return response()->json([
                'message' => 'Fee assigned successfully',
                'assignment' => $assignment,
                'ledger_entry' => $ledger,
            ], 201);
        });
    }
}
