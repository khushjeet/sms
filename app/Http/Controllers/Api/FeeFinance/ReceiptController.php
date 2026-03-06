<?php

namespace App\Http\Controllers\Api\FeeFinance;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Services\Accounting\AccountingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReceiptController extends Controller
{
    /**
     * Legacy compatibility endpoint.
     * Canonical collection flow is payments + ledger; this route now writes to payments.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'amount' => 'required|numeric|min:0.01',
            'paid_at' => 'required|date',
            'payment_method' => 'nullable|in:cash,cheque,online,card,upi',
            'transaction_id' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($validated) {
            $enrollment = Enrollment::with('academicYear')
                ->where('student_id', (int) $validated['student_id'])
                ->where('academic_year_id', (int) $validated['academic_year_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $actorId = Auth::id();
            $paidAt = Carbon::parse($validated['paid_at']);
            $receiptNumber = 'RCPT-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6));

            $payment = Payment::create([
                'enrollment_id' => $enrollment->id,
                'receipt_number' => $receiptNumber,
                'amount' => round((float) $validated['amount'], 2),
                'payment_date' => $paidAt->toDateString(),
                'payment_method' => $validated['payment_method'] ?? 'cash',
                'transaction_id' => $validated['transaction_id'] ?? null,
                'remarks' => $validated['notes'] ?? null,
                'received_by' => $actorId,
                'is_refunded' => false,
            ]);

            $accounting = app(AccountingService::class);
            $narration = 'Payment received (legacy receipts endpoint)';
            $notes = trim((string) ($validated['notes'] ?? ''));
            if ($notes !== '') {
                $narration .= ' | ' . $notes;
            }
            $posted = $accounting->postPaymentReceived(
                $enrollment,
                (int) $payment->id,
                (float) $validated['amount'],
                (string) ($validated['payment_method'] ?? 'cash'),
                $paidAt,
                $narration
            );

            $ledger = $posted['student_fee_ledger'];

            AuditLog::log('create', $payment, null, $payment->toArray(), 'Payment recorded via legacy receipts endpoint');
            AuditLog::log('create', $ledger, null, $ledger->toArray(), 'Ledger credit (projection) created from payment');

            return response()->json([
                'message' => 'Recorded via payments flow (receipts endpoint is compatibility mode).',
                'id' => $payment->id,
                'receipt_number' => $payment->receipt_number,
                'student_id' => (int) $validated['student_id'],
                'amount' => (float) $payment->amount,
                'payment_method' => $payment->payment_method,
                'date' => $payment->payment_date?->toDateString(),
                'notes' => $payment->remarks,
            ], 201);
        });
    }
}
