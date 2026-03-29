<?php

namespace App\Http\Controllers\Api\FeeFinance;

use App\Http\Controllers\Controller;
use App\Jobs\NotifyPaymentRecordedJob;
use App\Jobs\NotifyStudentLedgerRecordedJob;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\StudentFeeLedger;
use App\Models\StudentTransportAssignment;
use App\Services\Accounting\AccountingService;
use App\Services\InAppNotificationService;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'enrollment_id' => 'required|exists:enrollments,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date',
            'payment_method' => 'nullable|in:cash,cheque,online,card,upi',
            'transaction_id' => 'nullable|string',
            'remarks' => 'nullable|string',
            'receipt_number' => 'nullable|string|unique:payments,receipt_number',
        ]);

        return DB::transaction(function () use ($validated) {
            $enrollment = Enrollment::findOrFail($validated['enrollment_id']);
            $receiptNumber = $validated['receipt_number'] ?? $this->generateReceiptNumber();
            $actorId = Auth::id();

            $payment = Payment::create([
                'enrollment_id' => $validated['enrollment_id'],
                'receipt_number' => $receiptNumber,
                'amount' => $validated['amount'],
                'payment_date' => $validated['payment_date'],
                'payment_method' => $validated['payment_method'] ?? 'cash',
                'transaction_id' => $validated['transaction_id'] ?? null,
                'remarks' => $validated['remarks'] ?? null,
                'received_by' => $actorId,
                'is_refunded' => false,
            ]);

            $accounting = app(AccountingService::class);
            $narration = 'Payment received';
            $remarks = trim((string) ($validated['remarks'] ?? ''));
            if ($remarks !== '') {
                $narration .= ' | ' . $remarks;
            }
            $posted = $accounting->postPaymentReceived(
                $enrollment,
                (int) $payment->id,
                (float) $validated['amount'],
                (string) ($validated['payment_method'] ?? 'cash'),
                \Carbon\Carbon::parse($validated['payment_date']),
                $narration
            );
            $ledger = $posted['student_fee_ledger'];

            AuditLog::log('create', $payment, null, $payment->toArray(), 'Payment recorded');
            AuditLog::log('create', $ledger, null, $ledger->toArray(), 'Ledger credit (projection) created from payment');

            DB::afterCommit(function () use ($payment) {
                NotifyPaymentRecordedJob::dispatch((int) $payment->id);
                app(InAppNotificationService::class)->notifyPaymentRecorded($payment);
            });

            return response()->json([
                'message' => 'Payment recorded successfully',
                'data' => $payment
            ], 201);
        });
    }

    public function byEnrollment($id)
    {
        $enrollment = Enrollment::findOrFail($id);

        $payments = Payment::where('enrollment_id', $enrollment->id)
            ->orderByDesc('payment_date')
            ->get();

        return response()->json([
            'enrollment_id' => $enrollment->id,
            'total_paid' => (float) StudentFeeLedger::where('enrollment_id', $enrollment->id)
                ->where('transaction_type', 'credit')
                ->sum('amount'),
            'payments' => $payments,
        ]);
    }

    public function receipt($id)
    {
        $payment = Payment::with([
            'enrollment.student.user',
            'enrollment.academicYear',
            'enrollment.section.class'
        ])->findOrFail($id);

        return response()->json($payment);
    }

    public function receiptHtml($id)
    {
        $payment = Payment::with([
            'enrollment.student.user',
            'enrollment.academicYear',
            'enrollment.section.class'
        ])->findOrFail($id);

        if ((float) $payment->amount <= 0) {
            return response()->json([
                'message' => 'Receipt can only be generated for credited (positive) payments.'
            ], 422);
        }

        if ((bool) ($payment->is_refunded ?? false)) {
            return response()->json([
                'message' => 'Receipt cannot be generated for refunded payments.'
            ], 422);
        }

        $enrollment = $payment->enrollment;
        $student = $enrollment?->student;
        $user = $student?->user;
        $studentName = trim(($user?->first_name ?? '') . ' ' . ($user?->last_name ?? '')) ?: 'N/A';
        $admissionNumber = $student?->admission_number ?? 'N/A';
        $className = $enrollment?->section?->class?->name ?? $enrollment?->classModel?->name ?? 'N/A';
        $sectionName = $enrollment?->section?->name ?? 'N/A';

        $html = view('receipts.payment-receipt', [
            'receiptNumber' => $payment->receipt_number,
            'paidAt' => $payment->payment_date,
            'paymentMethod' => $payment->payment_method,
            'amount' => $payment->amount,
            'studentName' => $studentName,
            'admissionNumber' => $admissionNumber,
            'classSection' => $className . ' / ' . $sectionName,
            'enrollmentId' => $enrollment?->id ?? $payment->enrollment_id,
            'reference' => 'PAYMENT#' . $payment->id,
            'remarks' => $payment->remarks,
        ])->render();

        return response($html, 200)->header('Content-Type', 'text/html');
    }

    public function refund(Request $request, $id)
    {
        $validated = $request->validate([
            'refund_reason' => 'required|string',
            'refund_date' => 'nullable|date',
        ]);

        return DB::transaction(function () use ($id, $validated) {
            $payment = Payment::whereKey((int) $id)->lockForUpdate()->firstOrFail();

            if ($payment->reversal()->exists()) {
                return response()->json([
                    'message' => 'Payment already refunded'
                ], 422);
            }

            if ((float) $payment->amount <= 0) {
                return response()->json([
                    'message' => 'Only positive payments can be refunded'
                ], 422);
            }

            $actorId = Auth::id();
            $refundDate = $validated['refund_date'] ?? now()->toDateString();
            $enrollment = Enrollment::findOrFail($payment->enrollment_id);

            try {
                $refund = Payment::create([
                    'enrollment_id' => $payment->enrollment_id,
                    'receipt_number' => $this->generateReceiptNumber(),
                    'amount' => $payment->amount * -1,
                    'payment_date' => $refundDate,
                    'payment_method' => $payment->payment_method,
                    'transaction_id' => $payment->transaction_id,
                    'remarks' => 'Refund: ' . $validated['refund_reason'],
                    'received_by' => $actorId,
                    'is_refunded' => true,
                    'reversal_of_payment_id' => $payment->id,
                    'refunded_by' => $actorId,
                    'refunded_at' => now(),
                    'refund_reason' => $validated['refund_reason'],
                ]);
            } catch (QueryException $e) {
                return response()->json([
                    'message' => 'Payment already refunded'
                ], 422);
            }

            $accounting = app(AccountingService::class);
            $posted = $accounting->postRefundPaid(
                $enrollment,
                (int) $refund->id,
                abs((float) $payment->amount),
                (string) ($payment->payment_method ?? 'cash'),
                \Carbon\Carbon::parse($refundDate),
                'Refund: ' . $validated['refund_reason']
            );
            $ledger = $posted['student_fee_ledger'];

            AuditLog::log('create', $refund, null, $refund->toArray(), 'Refund recorded');
            AuditLog::log('create', $ledger, null, $ledger->toArray(), 'Ledger debit (projection) created from refund');

            DB::afterCommit(function () use ($ledger, $payment, $refund) {
                NotifyStudentLedgerRecordedJob::dispatch(
                    (int) $ledger->id,
                    'Refund processed',
                    'A refund has been posted to the student account.',
                    array_values(array_filter([
                        'Original Receipt No: ' . ($payment->receipt_number ?: '-'),
                        'Refund Receipt No: ' . ($refund->receipt_number ?: '-'),
                    ]))
                );
            });

            return response()->json([
                'message' => 'Refund processed successfully',
                'data' => $refund
            ]);
        });
    }

    public function unifiedReceipt($id)
    {
        $enrollment = Enrollment::with([
            'student.user',
            'academicYear',
            'section.class',
            'feeAssignment',
            'optionalServices',
            'payments'
        ])->findOrFail($id);

        $transport = StudentTransportAssignment::with(['route', 'stop'])
            ->where('enrollment_id', $enrollment->id)
            ->where('status', 'active')
            ->first();

        $totalDebits = (float) $enrollment->feeLedgerEntries()
            ->where('transaction_type', 'debit')
            ->sum('amount');
        $totalCredits = (float) $enrollment->feeLedgerEntries()
            ->where('transaction_type', 'credit')
            ->sum('amount');
        $pending = $totalDebits - $totalCredits;

        return response()->json([
            'enrollment_id' => $enrollment->id,
            'student' => [
                'id' => $enrollment->student?->id,
                'name' => $enrollment->student?->full_name,
                'admission_number' => $enrollment->student?->admission_number,
            ],
            'academic_year' => $enrollment->academicYear?->name,
            'class' => $enrollment->section?->class?->name,
            'section' => $enrollment->section?->name,
            'fee_assignment' => $enrollment->feeAssignment,
            'optional_services' => $enrollment->optionalServices,
            'transport' => $transport,
            'payments' => $enrollment->payments,
            'totals' => [
                'total_fee' => round($totalDebits, 2),
                'total_paid' => round($totalCredits, 2),
                'pending_due' => $pending,
            ],
        ]);
    }

    private function generateReceiptNumber(): string
    {
        return 'RCPT-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6));
    }
}
