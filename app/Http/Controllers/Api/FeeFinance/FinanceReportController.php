<?php

namespace App\Http\Controllers\Api\FeeFinance;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\StudentTransportAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class FinanceReportController extends Controller
{
    public function due(Request $request)
    {
        $query = Enrollment::with([
            'student.user',
            'academicYear',
            'section.class',
        ])->where('status', 'active');

        if ($request->has('academic_year_id')) {
            $query->where('academic_year_id', $request->academic_year_id);
        }

        if ($request->has('section_id')) {
            $query->where('section_id', $request->section_id);
        }

        $enrollments = $query->get();

        $enrollmentIds = $enrollments->pluck('id')->all();
        $ledgerTotals = collect();

        if (!empty($enrollmentIds)) {
            $ledgerTotals = DB::table('student_fee_ledger')
                ->select('enrollment_id')
                ->selectRaw("SUM(CASE WHEN transaction_type = 'debit' THEN amount ELSE 0 END) as debits")
                ->selectRaw("SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE 0 END) as credits")
                ->whereIn('enrollment_id', $enrollmentIds)
                ->groupBy('enrollment_id')
                ->get()
                ->keyBy('enrollment_id');
        }

        $report = $enrollments->map(function ($enrollment) use ($ledgerTotals) {
            $totals = $ledgerTotals->get($enrollment->id);
            $debits = (float) ($totals->debits ?? 0);
            $credits = (float) ($totals->credits ?? 0);
            $balance = $debits - $credits;

            return [
                'enrollment_id' => $enrollment->id,
                'student' => $enrollment->student?->user?->first_name . ' ' . $enrollment->student?->user?->last_name,
                'academic_year' => $enrollment->academicYear?->name,
                'class' => $enrollment->section?->class?->name,
                'section' => $enrollment->section?->name,
                'total_debits' => round($debits, 2),
                'total_credits' => round($credits, 2),
                'balance_due' => round($balance, 2),
                'status' => $balance > 0 ? 'unpaid' : 'paid',
            ];
        })->values();

        return response()->json([
            'count' => $report->count(),
            'data' => $report,
        ]);
    }

    public function collection(Request $request)
    {
        $ledgerQuery = DB::table('student_fee_ledger')
            ->whereIn('reference_type', ['payment', 'refund', 'receipt']);

        if ($request->has('start_date')) {
            $ledgerQuery->whereDate('posted_at', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $ledgerQuery->whereDate('posted_at', '<=', $request->end_date);
        }

        $ledgerTotals = $ledgerQuery
            ->selectRaw("SUM(CASE WHEN transaction_type = 'credit' AND reference_type IN ('payment','receipt') THEN amount ELSE 0 END) as collections")
            ->selectRaw("SUM(CASE WHEN transaction_type = 'debit' AND reference_type = 'refund' THEN amount ELSE 0 END) as refunds")
            ->first();

        $collections = (float) ($ledgerTotals->collections ?? 0);
        $refunds = (float) ($ledgerTotals->refunds ?? 0);

        $paymentsQuery = Payment::query()->where('amount', '>', 0);
        if ($request->has('start_date')) {
            $paymentsQuery->whereDate('payment_date', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $paymentsQuery->whereDate('payment_date', '<=', $request->end_date);
        }
        $payments = $paymentsQuery->orderBy('payment_date')->get();

        $summary = [
            'total_amount' => round($collections, 2),
            'refunds' => round($refunds, 2),
            'net_amount' => round($collections - $refunds, 2),
            'total_count' => $payments->count(),
            'by_method' => $payments->groupBy('payment_method')->map->sum('amount'),
            'source_of_truth' => 'student_fee_ledger',
        ];

        return response()->json([
            'summary' => $summary,
            'payments' => $payments,
        ]);
    }

    public function routeWise(Request $request)
    {
        $query = StudentTransportAssignment::with(['route', 'stop'])
            ->where('status', 'active');

        if ($request->has('academic_year_id')) {
            $query->where('academic_year_id', $request->academic_year_id);
        }

        $records = $query->get();

        $report = $records->groupBy('route_id')->map(function ($group) {
            $route = $group->first()->route;
            $count = $group->count();
            $totalAmount = $group->sum(function ($item) {
                return (float) ($item->stop?->fee_amount ?? $item->route?->fee_amount ?? 0);
            });
            $avgAmount = $count > 0 ? ($totalAmount / $count) : 0;

            return [
                'route_id' => $route?->id,
                'route_name' => $route?->route_name,
                'route_number' => $route?->route_number,
                'student_count' => $count,
                'fee_amount' => round($avgAmount, 2),
                'total_amount' => round($totalAmount, 2),
            ];
        })->values();

        return response()->json([
            'count' => $report->count(),
            'data' => $report,
        ]);
    }
}
