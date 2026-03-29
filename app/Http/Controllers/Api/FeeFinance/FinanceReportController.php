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
        $validated = $request->validate([
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'class_id' => ['nullable', 'integer', 'exists:classes,id'],
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $query = Enrollment::with([
            'student.user',
            'academicYear',
            'section.class',
        ])->where('status', 'active');

        if (!empty($validated['academic_year_id'])) {
            $query->where('academic_year_id', (int) $validated['academic_year_id']);
        }

        if (!empty($validated['class_id'])) {
            $query->where('class_id', (int) $validated['class_id']);
        }

        if (!empty($validated['section_id'])) {
            $query->where('section_id', (int) $validated['section_id']);
        }

        $enrollments = $query->get();

        $enrollmentIds = $enrollments->pluck('id')->all();
        $ledgerTotals = collect();

        if (!empty($enrollmentIds)) {
            $ledgerQuery = DB::table('student_fee_ledger')
                ->select('enrollment_id')
                ->selectRaw("SUM(CASE WHEN transaction_type = 'debit' THEN amount ELSE 0 END) as debits")
                ->selectRaw("SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE 0 END) as credits")
                ->whereIn('enrollment_id', $enrollmentIds);

            if (!empty($validated['start_date'])) {
                $ledgerQuery->whereDate('posted_at', '>=', $validated['start_date']);
            }

            if (!empty($validated['end_date'])) {
                $ledgerQuery->whereDate('posted_at', '<=', $validated['end_date']);
            }

            $ledgerTotals = $ledgerQuery
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
        $validated = $request->validate([
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'class_id' => ['nullable', 'integer', 'exists:classes,id'],
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $ledgerQuery = DB::table('student_fee_ledger')
            ->join('enrollments', 'enrollments.id', '=', 'student_fee_ledger.enrollment_id')
            ->whereIn('reference_type', ['payment', 'refund', 'receipt']);

        if (!empty($validated['academic_year_id'])) {
            $ledgerQuery->where('enrollments.academic_year_id', (int) $validated['academic_year_id']);
        }

        if (!empty($validated['class_id'])) {
            $ledgerQuery->where('enrollments.class_id', (int) $validated['class_id']);
        }

        if (!empty($validated['section_id'])) {
            $ledgerQuery->where('enrollments.section_id', (int) $validated['section_id']);
        }

        if (!empty($validated['start_date'])) {
            $ledgerQuery->whereDate('posted_at', '>=', $validated['start_date']);
        }
        if (!empty($validated['end_date'])) {
            $ledgerQuery->whereDate('posted_at', '<=', $validated['end_date']);
        }

        $ledgerTotals = $ledgerQuery
            ->selectRaw("SUM(CASE WHEN transaction_type = 'credit' AND reference_type IN ('payment','receipt') THEN amount ELSE 0 END) as collections")
            ->selectRaw("SUM(CASE WHEN transaction_type = 'debit' AND reference_type = 'refund' THEN amount ELSE 0 END) as refunds")
            ->first();

        $collections = (float) ($ledgerTotals->collections ?? 0);
        $refunds = (float) ($ledgerTotals->refunds ?? 0);

        $paymentsQuery = Payment::query()
            ->with('enrollment')
            ->where('amount', '>', 0);

        if (!empty($validated['academic_year_id'])) {
            $paymentsQuery->whereHas('enrollment', fn ($query) => $query->where('academic_year_id', (int) $validated['academic_year_id']));
        }

        if (!empty($validated['class_id'])) {
            $paymentsQuery->whereHas('enrollment', fn ($query) => $query->where('class_id', (int) $validated['class_id']));
        }

        if (!empty($validated['section_id'])) {
            $paymentsQuery->whereHas('enrollment', fn ($query) => $query->where('section_id', (int) $validated['section_id']));
        }

        if (!empty($validated['start_date'])) {
            $paymentsQuery->whereDate('payment_date', '>=', $validated['start_date']);
        }
        if (!empty($validated['end_date'])) {
            $paymentsQuery->whereDate('payment_date', '<=', $validated['end_date']);
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
        $validated = $request->validate([
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'class_id' => ['nullable', 'integer', 'exists:classes,id'],
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $query = StudentTransportAssignment::with(['route', 'stop', 'enrollment'])
            ->where('status', 'active');

        if (!empty($validated['academic_year_id'])) {
            $query->whereHas('enrollment', fn ($enrollmentQuery) => $enrollmentQuery->where('academic_year_id', (int) $validated['academic_year_id']));
        }

        if (!empty($validated['class_id'])) {
            $query->whereHas('enrollment', fn ($enrollmentQuery) => $enrollmentQuery->where('class_id', (int) $validated['class_id']));
        }

        if (!empty($validated['section_id'])) {
            $query->whereHas('enrollment', fn ($enrollmentQuery) => $enrollmentQuery->where('section_id', (int) $validated['section_id']));
        }

        if (!empty($validated['start_date'])) {
            $query->where(function ($assignmentQuery) use ($validated) {
                $assignmentQuery
                    ->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $validated['start_date']);
            });
        }

        if (!empty($validated['end_date'])) {
            $query->whereDate('start_date', '<=', $validated['end_date']);
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
                'enrollment_ids' => $group->pluck('enrollment_id')
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all(),
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
