<?php

namespace App\Services\StudentDashboard;

use App\Models\Payment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class FeeSummaryService
{
    public function build(array $enrollmentIds): array
    {
        if (empty($enrollmentIds)) {
            return [
                'total_fee' => 0,
                'paid_amount' => 0,
                'pending_amount' => 0,
                'last_payment_date' => null,
                'last_receipt_number' => null,
                'receipt_download_url' => null,
                'receipt_download_available' => false,
                'source' => 'fee_summary_service',
            ];
        }

        sort($enrollmentIds);
        $cacheKey = 'student_dashboard:fee_summary:' . md5(json_encode($enrollmentIds));

        return Cache::remember($cacheKey, now()->addMinutes(3), function () use ($enrollmentIds) {
            $ledger = DB::table('student_fee_ledger')
                ->whereIn('enrollment_id', $enrollmentIds)
                ->selectRaw("SUM(CASE WHEN transaction_type = 'debit' THEN amount ELSE 0 END) as debits")
                ->selectRaw("SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE 0 END) as credits")
                ->first();

            $lastPayment = Payment::query()
                ->whereIn('enrollment_id', $enrollmentIds)
                ->where('amount', '>', 0)
                ->whereDoesntHave('reversal')
                ->orderByDesc('payment_date')
                ->orderByDesc('id')
                ->first();

            $totalFee = (float) ($ledger->debits ?? 0);
            $paid = (float) ($ledger->credits ?? 0);

            return [
                'total_fee' => $totalFee,
                'paid_amount' => $paid,
                'pending_amount' => max(0, $totalFee - $paid),
                'last_payment_date' => $lastPayment?->payment_date?->toDateString(),
                'last_receipt_number' => $lastPayment?->receipt_number,
                'receipt_download_url' => null,
                'receipt_download_available' => false,
                'source' => 'fee_summary_service',
            ];
        });
    }
}
