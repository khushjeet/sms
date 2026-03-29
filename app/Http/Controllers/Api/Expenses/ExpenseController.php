<?php

namespace App\Http\Controllers\Api\Expenses;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\ExpenseReceipt;
use App\Services\Accounting\AccountingService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $query = Expense::query()
            ->with('receipts')
            ->orderByDesc('expense_date')
            ->orderByDesc('id');

        if ($request->filled('start_date')) {
            $query->whereDate('expense_date', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('expense_date', '<=', $request->input('end_date'));
        }
        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }
        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->input('payment_method'));
        }
        if ($request->filled('is_reversal')) {
            $query->where('is_reversal', filter_var($request->input('is_reversal'), FILTER_VALIDATE_BOOLEAN));
        }

        $rows = $query->paginate((int) $request->input('per_page', 25));

        $summaryQuery = Expense::query();
        if ($request->filled('start_date')) {
            $summaryQuery->whereDate('expense_date', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $summaryQuery->whereDate('expense_date', '<=', $request->input('end_date'));
        }

        $total = (float) (clone $summaryQuery)->where('is_reversal', false)->sum('amount');
        $reversed = (float) (clone $summaryQuery)->where('is_reversal', true)->sum('amount');

        return response()->json([
            'summary' => [
                'total_expense' => round($total, 2),
                'reversed_amount' => round($reversed, 2),
                'net_expense' => round($total - $reversed, 2),
            ],
            'data' => $rows,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'expense_date' => 'required|date',
            'category' => 'required|string|max:100',
            'description' => 'nullable|string',
            'vendor_name' => 'nullable|string|max:255',
            'amount' => 'required|numeric|min:0.01|max:9999999999.99',
            'payment_method' => 'required|in:cash,cheque,online,card,upi,bank_transfer,other',
            'payment_account_code' => 'nullable|string|exists:accounts,code',
            'expense_account_code' => 'nullable|string|exists:accounts,code',
            'reference_number' => 'nullable|string|max:255',
            'receipt_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp,xls,xlsx,csv,doc,docx|max:10240',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $expense = Expense::create([
                'expense_number' => $this->generateExpenseNumber(),
                'expense_date' => $validated['expense_date'],
                'category' => trim($validated['category']),
                'description' => $validated['description'] ?? null,
                'vendor_name' => $validated['vendor_name'] ?? null,
                'amount' => $validated['amount'],
                'payment_method' => $validated['payment_method'],
                'payment_account_code' => $validated['payment_account_code'] ?? $this->resolvePaymentAccountCode($validated['payment_method']),
                'expense_account_code' => $this->resolveExpenseAccountCode($validated['expense_account_code'] ?? null),
                'reference_number' => $validated['reference_number'] ?? null,
                'created_by' => Auth::id(),
                'is_reversal' => false,
                'reversal_of_expense_id' => null,
            ]);

            $narration = 'Expense posted: ' . $expense->category;
            if (!empty($expense->description)) {
                $narration .= ' | ' . Str::limit((string) $expense->description, 150, '');
            }

            $journal = app(AccountingService::class)->postExpensePaid(
                (int) $expense->id,
                (float) $expense->amount,
                (string) $expense->payment_account_code,
                (string) $expense->expense_account_code,
                Carbon::parse($expense->expense_date),
                $narration
            );

            AuditLog::log('create', $expense, null, $expense->toArray(), 'Expense entry created');
            AuditLog::log('create', $journal, null, $journal->toArray(), 'Expense journal entry created');

            if ($request->hasFile('receipt_file')) {
                $receipt = $this->storeExpenseReceipt($expense, $request->file('receipt_file'));
                AuditLog::log('create', $receipt, null, $receipt->toArray(), 'Expense receipt file uploaded');
            }

            return response()->json([
                'message' => 'Expense recorded successfully',
                'data' => $expense->fresh()->load('receipts'),
            ], 201);
        });
    }

    public function reverse(Request $request, int $id)
    {
        $validated = $request->validate([
            'reversal_reason' => 'required|string',
            'reversal_date' => 'nullable|date',
        ]);

        return DB::transaction(function () use ($id, $validated) {
            $original = Expense::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            if ($original->is_reversal) {
                return response()->json([
                    'message' => 'A reversal entry cannot be reversed again.',
                ], 422);
            }

            if ($original->reversal()->exists()) {
                return response()->json([
                    'message' => 'Expense already reversed.',
                ], 422);
            }

            $reversalDate = $validated['reversal_date'] ?? now()->toDateString();
            $actorId = Auth::id();

            try {
                $reversal = Expense::create([
                    'expense_number' => $this->generateExpenseNumber(),
                    'expense_date' => $reversalDate,
                    'category' => $original->category,
                    'description' => 'Reversal of ' . $original->expense_number,
                    'vendor_name' => $original->vendor_name,
                    'amount' => $original->amount,
                    'payment_method' => $original->payment_method,
                    'payment_account_code' => $original->payment_account_code,
                    'expense_account_code' => $original->expense_account_code,
                    'reference_number' => $original->reference_number,
                    'created_by' => $actorId,
                    'is_reversal' => true,
                    'reversal_of_expense_id' => $original->id,
                    'reversed_by' => $actorId,
                    'reversed_at' => now(),
                    'reversal_reason' => $validated['reversal_reason'],
                ]);
            } catch (QueryException) {
                return response()->json([
                    'message' => 'Expense already reversed.',
                ], 422);
            }

            $journal = app(AccountingService::class)->postExpenseReversal(
                (int) $reversal->id,
                (float) $reversal->amount,
                (string) $reversal->payment_account_code,
                (string) $reversal->expense_account_code,
                Carbon::parse($reversalDate),
                'Expense reversal: ' . $validated['reversal_reason']
            );

            AuditLog::log('create', $reversal, null, $reversal->toArray(), 'Expense reversal entry created');
            AuditLog::log('create', $journal, null, $journal->toArray(), 'Expense reversal journal entry created');

            return response()->json([
                'message' => 'Expense reversed successfully',
                'data' => $reversal->fresh()->load('receipts'),
            ], 201);
        });
    }

    public function uploadReceipt(Request $request, int $id)
    {
        $validated = $request->validate([
            'receipt_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp,xls,xlsx,csv,doc,docx|max:10240',
        ]);

        $expense = Expense::query()->findOrFail($id);
        if (!isset($validated['receipt_file'])) {
            return response()->json([
                'message' => 'No receipt file uploaded. This field is optional.',
                'data' => null,
            ]);
        }

        return DB::transaction(function () use ($expense, $validated) {
            $receipt = $this->storeExpenseReceipt($expense, $validated['receipt_file']);
            AuditLog::log('create', $receipt, null, $receipt->toArray(), 'Expense receipt file uploaded');

            return response()->json([
                'message' => 'Receipt uploaded successfully',
                'data' => $receipt,
            ], 201);
        });
    }

    public function file(int $id)
    {
        $receipt = ExpenseReceipt::query()->findOrFail($id);
        if (!Storage::disk('public')->exists($receipt->file_path)) {
            return response()->json(['message' => 'Receipt file not found'], 404);
        }

        return response()->file(Storage::disk('public')->path($receipt->file_path));
    }

    public function report(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'group_by' => 'nullable|in:month,year,category',
        ]);

        $groupBy = $validated['group_by'] ?? 'month';
        $startDate = $validated['start_date'] ?? null;
        $endDate = $validated['end_date'] ?? null;

        $base = Expense::query();
        if ($startDate) {
            $base->whereDate('expense_date', '>=', $startDate);
        }
        if ($endDate) {
            $base->whereDate('expense_date', '<=', $endDate);
        }

        $totalEntries = (int) (clone $base)->count();
        $originalEntries = (int) (clone $base)->where('is_reversal', false)->count();
        $reversalEntries = (int) (clone $base)->where('is_reversal', true)->count();
        $totalExpense = (float) (clone $base)->where('is_reversal', false)->sum('amount');
        $totalReversed = (float) (clone $base)->where('is_reversal', true)->sum('amount');
        $netExpense = $totalExpense - $totalReversed;

        $receiptsAttached = (int) ExpenseReceipt::query()
            ->whereIn('expense_id', (clone $base)->pluck('id'))
            ->count();
        $coverage = $originalEntries > 0 ? round(($receiptsAttached / $originalEntries) * 100, 2) : 0.0;

        $journalLinked = (int) DB::table('journal_entries')
            ->whereIn('source_type', ['expense', 'expense_reversal'])
            ->whereIn('source_id', (clone $base)->pluck('id'))
            ->count();

        $trend = [];
        if ($groupBy === 'category') {
            $trend = (clone $base)
                ->select('category')
                ->selectRaw('SUM(CASE WHEN is_reversal = 0 THEN amount ELSE 0 END) as total_expense')
                ->selectRaw('SUM(CASE WHEN is_reversal = 1 THEN amount ELSE 0 END) as total_reversed')
                ->groupBy('category')
                ->orderBy('category')
                ->get()
                ->map(fn ($row) => [
                    'bucket' => (string) $row->category,
                    'total_expense' => round((float) $row->total_expense, 2),
                    'total_reversed' => round((float) $row->total_reversed, 2),
                    'net_expense' => round((float) $row->total_expense - (float) $row->total_reversed, 2),
                ])
                ->values();
        } else {
            $formatExpression = $this->buildTrendBucketExpression($groupBy);
            $trend = (clone $base)
                ->selectRaw($formatExpression . ' as bucket')
                ->selectRaw('SUM(CASE WHEN is_reversal = 0 THEN amount ELSE 0 END) as total_expense')
                ->selectRaw('SUM(CASE WHEN is_reversal = 1 THEN amount ELSE 0 END) as total_reversed')
                ->groupBy('bucket')
                ->orderBy('bucket')
                ->get()
                ->map(fn ($row) => [
                    'bucket' => (string) $row->bucket,
                    'total_expense' => round((float) $row->total_expense, 2),
                    'total_reversed' => round((float) $row->total_reversed, 2),
                    'net_expense' => round((float) $row->total_expense - (float) $row->total_reversed, 2),
                ])
                ->values();
        }

        $auditTrail = AuditLog::query()
            ->whereIn('model_type', [Expense::class, ExpenseReceipt::class])
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'model_type' => class_basename((string) $log->model_type),
                'model_id' => $log->model_id,
                'user_id' => $log->user_id,
                'reason' => $log->reason,
                'created_at' => $log->created_at?->toDateTimeString(),
            ]);

        $snapshot = [
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'group_by' => $groupBy,
            ],
            'summary' => [
                'total_entries' => $totalEntries,
                'original_entries' => $originalEntries,
                'reversal_entries' => $reversalEntries,
                'total_expense' => round($totalExpense, 2),
                'total_reversed' => round($totalReversed, 2),
                'net_expense' => round($netExpense, 2),
                'receipt_attachment_count' => $receiptsAttached,
                'receipt_coverage_percent' => $coverage,
                'journal_linked_count' => $journalLinked,
                'journal_unlinked_count' => max(0, $totalEntries - $journalLinked),
            ],
            'trend' => $trend,
        ];

        return response()->json([
            ...$snapshot,
            'audit_trail' => $auditTrail,
            'report_fingerprint' => hash('sha256', json_encode($snapshot)),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    private function buildTrendBucketExpression(string $groupBy): string
    {
        $isYear = $groupBy === 'year';

        return match (DB::getDriverName()) {
            'sqlite' => $isYear
                ? "strftime('%Y', expense_date)"
                : "strftime('%Y-%m', expense_date)",
            default => $isYear
                ? "DATE_FORMAT(expense_date, '%Y')"
                : "DATE_FORMAT(expense_date, '%Y-%m')",
        };
    }

    public function downloadEntries(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        $startDate = $validated['start_date'] ?? null;
        $endDate = $validated['end_date'] ?? null;

        $query = Expense::query()->with('receipts')->orderBy('expense_date')->orderBy('id');
        if ($startDate) {
            $query->whereDate('expense_date', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('expense_date', '<=', $endDate);
        }

        $rows = $query->get();

        $filename = 'expense_entries_' . now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'expense_id',
                'expense_number',
                'expense_date',
                'category',
                'vendor_name',
                'amount',
                'payment_method',
                'is_reversal',
                'reversal_of_expense_id',
                'reference_number',
                'receipts_count',
                'receipt_file_names',
                'created_by',
                'created_at',
            ]);

            foreach ($rows as $expense) {
                $receipts = $expense->receipts ?? collect();
                $receiptNames = $receipts->pluck('original_name')->implode(' | ');
                fputcsv($handle, [
                    $expense->id,
                    $expense->expense_number,
                    optional($expense->expense_date)->toDateString(),
                    $expense->category,
                    $expense->vendor_name,
                    $expense->amount,
                    $expense->payment_method,
                    $expense->is_reversal ? 'yes' : 'no',
                    $expense->reversal_of_expense_id,
                    $expense->reference_number,
                    $receipts->count(),
                    $receiptNames,
                    $expense->created_by,
                    optional($expense->created_at)->toDateTimeString(),
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    private function generateExpenseNumber(): string
    {
        return 'EXP-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6));
    }

    private function resolvePaymentAccountCode(string $paymentMethod): string
    {
        return (string) config(
            'accounting.payment_method_accounts.' . $paymentMethod,
            (string) config('accounting.accounts.bank_main')
        );
    }

    private function resolveExpenseAccountCode(?string $requestedCode = null): string
    {
        $code = trim((string) ($requestedCode ?: config('accounting.accounts.expense_operating', 'EXPENSE_OPERATING')));
        if ($code === '') {
            $code = 'EXPENSE_OPERATING';
        }

        $account = Account::query()->where('code', $code)->first();
        if (!$account) {
            Account::create([
                'code' => $code,
                'name' => 'Operating Expense',
                'type' => 'expense',
                'is_cash' => false,
                'is_bank' => false,
                'is_active' => true,
            ]);
            return $code;
        }

        if ($account->type !== 'expense') {
            throw ValidationException::withMessages([
                'expense_account_code' => "Configured account code '{$code}' exists but is not an expense account.",
            ]);
        }

        if (!(bool) $account->is_active) {
            $account->update(['is_active' => true]);
        }

        return $code;
    }

    private function storeExpenseReceipt(Expense $expense, \Illuminate\Http\UploadedFile $file): ExpenseReceipt
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $storedName = Str::uuid()->toString() . ($extension ? ".{$extension}" : '');
        $directory = 'expenses/receipts/' . now()->format('Y/m');
        $path = $file->storeAs($directory, $storedName, 'public');

        return ExpenseReceipt::create([
            'expense_id' => $expense->id,
            'file_name' => $storedName,
            'original_name' => (string) $file->getClientOriginalName(),
            'mime_type' => (string) $file->getClientMimeType(),
            'extension' => $extension,
            'size_bytes' => (int) $file->getSize(),
            'file_path' => $path,
            'uploaded_by' => Auth::id(),
        ]);
    }
}
