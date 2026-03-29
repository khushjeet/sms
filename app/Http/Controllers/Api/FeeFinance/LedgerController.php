<?php

namespace App\Http\Controllers\Api\FeeFinance;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentFeeLedger;
use App\Services\Accounting\AccountingService;
use App\Services\Email\EventNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LedgerController extends Controller
{
    public function byStudent(Request $request, $studentId)
    {
        $this->validateStatementFilters($request);

        $student = Student::with('user')->findOrFail((int) $studentId);
        $openingBalance = $this->calculateStudentOpeningBalance($request, (int) $studentId);
        $entries = $this->studentLedgerQuery($request, (int) $studentId)
            ->orderBy('posted_at')
            ->orderBy('id')
            ->get();

        return response()->json($this->buildStatementPayload($student, $entries, $request, $openingBalance));
    }

    public function downloadStudentLedger(Request $request, $studentId): StreamedResponse
    {
        $this->validateStatementFilters($request);

        $student = Student::with('user')->findOrFail((int) $studentId);
        $openingBalance = $this->calculateStudentOpeningBalance($request, (int) $studentId);
        $entries = $this->studentLedgerQuery($request, (int) $studentId)
            ->orderBy('posted_at')
            ->orderBy('id')
            ->get();

        $payload = $this->buildStatementPayload($student, $entries, $request, $openingBalance);
        $safeAdmission = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($student->admission_number ?? ('student_' . $student->id)));
        $filename = 'ledger_' . $safeAdmission . '_' . now()->format('Ymd_His') . '.csv';

        $response = new StreamedResponse(function () use ($payload) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'student_id',
                'student_name',
                'admission_number',
                'from_date',
                'to_date',
                'academic_year_id',
                'total_debits',
                'total_credits',
                'balance',
            ]);
            fputcsv($out, [
                $payload['student']['id'],
                $payload['student']['name'],
                $payload['student']['admission_number'],
                $payload['filters']['start_date'],
                $payload['filters']['end_date'],
                $payload['filters']['academic_year_id'],
                $payload['totals']['debits'],
                $payload['totals']['credits'],
                $payload['totals']['balance'],
            ]);
            fputcsv($out, []);
            fputcsv($out, [
                'entry_id',
                'posted_at',
                'enrollment_id',
                'academic_year_id',
                'reference_type',
                'reference_id',
                'transaction_type',
                'debit',
                'credit',
                'running_balance',
                'is_reversal',
                'narration',
            ]);

            foreach ($payload['entries'] as $entry) {
                fputcsv($out, [
                    $entry['id'],
                    $entry['posted_at'],
                    $entry['enrollment_id'],
                    $entry['academic_year_id'],
                    $entry['reference_type'],
                    $entry['reference_id'],
                    $entry['transaction_type'],
                    $entry['debit'],
                    $entry['credit'],
                    $entry['running_balance'],
                    $entry['is_reversal'] ? 'yes' : 'no',
                    $entry['narration'],
                ]);
            }

            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        return $response;
    }

    public function classLedger(Request $request, $classId)
    {
        $this->validateClassLedgerFilters($request, (int) $classId);

        $payload = $this->buildClassLedgerPayload($request, (int) $classId);

        return response()->json($payload);
    }

    public function classLedgerStatements(Request $request, $classId)
    {
        $this->validateClassLedgerFilters($request, (int) $classId);

        $payload = $this->buildClassLedgerStatementsPayload($request, (int) $classId);

        return response()->json($payload);
    }

    public function downloadClassLedger(Request $request, $classId): StreamedResponse
    {
        $this->validateClassLedgerFilters($request, (int) $classId);

        $payload = $this->buildClassLedgerPayload($request, (int) $classId);
        $classSafe = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($payload['class']['name'] ?? ('class_' . $classId)));
        $filename = 'class_ledger_' . $classSafe . '_' . now()->format('Ymd_His') . '.csv';

        $response = new StreamedResponse(function () use ($payload) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'class_id',
                'class_name',
                'academic_year_id',
                'students_count',
                'total_debits',
                'total_credits',
                'total_balance',
            ]);
            fputcsv($out, [
                $payload['class']['id'],
                $payload['class']['name'],
                $payload['filters']['academic_year_id'],
                $payload['summary']['students_count'],
                $payload['summary']['total_debits'],
                $payload['summary']['total_credits'],
                $payload['summary']['total_balance'],
            ]);
            fputcsv($out, []);
            fputcsv($out, [
                'ledger_serial_number',
                'student_name',
                'father_name',
                'mobile',
                'class',
                'section',
                'enrollment_id',
                'debits',
                'credits',
                'balance',
            ]);

            foreach ($payload['students'] as $row) {
                fputcsv($out, [
                    $row['ledger_serial_number'],
                    $row['student_name'],
                    $row['father_name'],
                    $row['mobile'],
                    $row['class'],
                    $row['section'],
                    $row['enrollment_id'],
                    $row['debits'],
                    $row['credits'],
                    $row['balance'],
                ]);
            }
            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        return $response;
    }

    public function byEnrollment(Request $request, $enrollmentId)
    {
        return StudentFeeLedger::where('enrollment_id', (int) $enrollmentId)
            ->orderBy('posted_at', 'desc')
            ->get();
    }

    public function balance(Request $request, $studentId)
    {
        $query = StudentFeeLedger::whereHas('enrollment', function ($q) use ($studentId) {
            $q->where('student_id', (int) $studentId);
        });

        if ($request->filled('academic_year_id')) {
            $yearId = $request->integer('academic_year_id');
            $query->whereHas('enrollment', function ($q) use ($yearId) {
                $q->where('academic_year_id', $yearId);
            });
        }

        $totals = $query->selectRaw("SUM(CASE WHEN transaction_type = 'debit' THEN amount ELSE 0 END) as debits")
            ->selectRaw("SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE 0 END) as credits")
            ->first();

        $debits = (float) ($totals->debits ?? 0);
        $credits = (float) ($totals->credits ?? 0);
        $balance = $debits - $credits;

        return response()->json([
            'student_id' => (int) $studentId,
            'balance' => round($balance, 2),
            'debits' => round($debits, 2),
            'credits' => round($credits, 2),
            'adjustments' => 0,
        ]);
    }

    public function balanceByEnrollment(Request $request, $enrollmentId)
    {
        $totals = StudentFeeLedger::where('enrollment_id', (int) $enrollmentId)
            ->selectRaw("SUM(CASE WHEN transaction_type = 'debit' THEN amount ELSE 0 END) as debits")
            ->selectRaw("SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE 0 END) as credits")
            ->first();

        $debits = (float) ($totals->debits ?? 0);
        $credits = (float) ($totals->credits ?? 0);

        return response()->json([
            'enrollment_id' => (int) $enrollmentId,
            'balance' => round($debits - $credits, 2),
            'debits' => round($debits, 2),
            'credits' => round($credits, 2),
        ]);
    }

    public function reverse(Request $request, $entryId)
    {
        $data = $request->validate([
            'reason' => 'required|string',
        ]);

        return DB::transaction(function () use ($entryId, $data) {
            $entry = StudentFeeLedger::findOrFail($entryId);

            if ($entry->journal_entry_id) {
                $accounting = app(AccountingService::class);
                $posted = $accounting->reverseJournalEntry(
                    (int) $entry->journal_entry_id,
                    (string) $data['reason'],
                    now()
                );

                foreach ($posted['student_fee_ledgers'] as $ledgerRow) {
                    AuditLog::log('create', $ledgerRow, null, $ledgerRow->toArray(), 'Ledger reversal (projection) created: ' . $data['reason']);
                }

                DB::afterCommit(function () use ($posted) {
                    foreach ($posted['student_fee_ledgers'] as $ledgerRow) {
                        app(EventNotificationService::class)->notifyStudentLedgerRecorded(
                            $ledgerRow->fresh(['enrollment.student.user', 'enrollment.student.profile', 'enrollment.student.parents.user', 'enrollment.section.class', 'enrollment.classModel', 'enrollment.academicYear']),
                            'Student ledger reversal posted',
                            'A reversal entry has been posted to the student account.'
                        );
                    }
                });

                return response()->json([
                    'journal_entry' => $posted['journal_entry'],
                    'ledger_entries' => $posted['student_fee_ledgers'],
                ], 201);
            }

            $reversal = StudentFeeLedger::create([
                'enrollment_id' => $entry->enrollment_id,
                'transaction_type' => $entry->transaction_type === 'debit' ? 'credit' : 'debit',
                'reference_type' => 'manual',
                'reference_id' => $entry->id,
                'amount' => $entry->amount,
                'posted_by' => Auth::id(),
                'posted_at' => now(),
                'narration' => $data['reason'],
                'is_reversal' => true,
                'reversal_of' => $entry->id,
            ]);

            AuditLog::log('create', $reversal, null, $reversal->toArray(), 'Ledger reversal created: ' . $data['reason']);

            DB::afterCommit(function () use ($reversal) {
                app(EventNotificationService::class)->notifyStudentLedgerRecorded(
                    $reversal->fresh(['enrollment.student.user', 'enrollment.student.profile', 'enrollment.student.parents.user', 'enrollment.section.class', 'enrollment.classModel', 'enrollment.academicYear']),
                    'Student ledger reversal posted',
                    'A reversal entry has been posted to the student account.'
                );
            });

            return response()->json($reversal, 201);
        });
    }

    public function postSpecialFee(Request $request, $enrollmentId)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'posted_at' => 'nullable|date',
            'narration' => 'required|string',
        ]);

        $enrollment = Enrollment::findOrFail($enrollmentId);
        $postedAt = isset($data['posted_at'])
            ? Carbon::parse($data['posted_at'])
            : now();

        $accounting = app(AccountingService::class);
        $posted = $accounting->postSpecialFee(
            $enrollment,
            (float) $data['amount'],
            (string) $data['narration'],
            $postedAt
        );

        $entry = $posted['student_fee_ledger'];
        AuditLog::log('create', $entry, null, $entry->toArray(), 'Special fee posted (projection)');

        DB::afterCommit(function () use ($entry) {
            app(EventNotificationService::class)->notifyStudentLedgerRecorded(
                $entry->fresh(['enrollment.student.user', 'enrollment.student.profile', 'enrollment.student.parents.user', 'enrollment.section.class', 'enrollment.classModel', 'enrollment.academicYear']),
                'Special fee posted',
                'A special fee has been added to the student account.'
            );
        });

        return response()->json($entry, 201);
    }

    private function studentLedgerQuery(Request $request, int $studentId)
    {
        $query = StudentFeeLedger::with(['enrollment'])
            ->whereHas('enrollment', function ($q) use ($studentId) {
                $q->where('student_id', $studentId);
            });

        if ($request->filled('academic_year_id')) {
            $yearId = $request->integer('academic_year_id');
            $query->whereHas('enrollment', function ($q) use ($yearId) {
                $q->where('academic_year_id', $yearId);
            });
        }

        if ($request->filled('start_date')) {
            $query->whereDate('posted_at', '>=', $request->date('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->whereDate('posted_at', '<=', $request->date('end_date'));
        }

        if ($request->filled('reference_type')) {
            $query->where('reference_type', (string) $request->input('reference_type'));
        }

        return $query;
    }

    private function validateStatementFilters(Request $request): void
    {
        $request->validate([
            'academic_year_id' => 'sometimes|integer|exists:academic_years,id',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'reference_type' => 'sometimes|string|max:50',
        ]);
    }

    private function validateClassLedgerFilters(Request $request, int $classId): void
    {
        $request->merge(['class_id' => $classId]);
        $request->validate([
            'class_id' => 'required|integer|exists:classes,id',
            'academic_year_id' => 'sometimes|integer|exists:academic_years,id',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
        ]);
    }

    private function buildClassLedgerPayload(Request $request, int $classId): array
    {
        $enrollmentsQuery = Enrollment::with([
            'classModel',
            'section',
            'student.user',
            'student.profile',
            'student.parents.user',
        ])->where('class_id', $classId);

        if ($request->filled('academic_year_id')) {
            $enrollmentsQuery->where('academic_year_id', $request->integer('academic_year_id'));
        }

        $enrollments = $enrollmentsQuery
            ->orderBy('id')
            ->get();

        $enrollmentIds = $enrollments->pluck('id')->all();
        $ledgerTotals = collect();

        if (!empty($enrollmentIds)) {
            $ledgerQuery = DB::table('student_fee_ledger')
                ->select('enrollment_id')
                ->selectRaw("SUM(CASE WHEN transaction_type = 'debit' THEN amount ELSE 0 END) as debits")
                ->selectRaw("SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE 0 END) as credits")
                ->whereIn('enrollment_id', $enrollmentIds);

            if ($request->filled('start_date')) {
                $ledgerQuery->whereDate('posted_at', '>=', $request->date('start_date'));
            }
            if ($request->filled('end_date')) {
                $ledgerQuery->whereDate('posted_at', '<=', $request->date('end_date'));
            }

            $ledgerTotals = $ledgerQuery->groupBy('enrollment_id')->get()->keyBy('enrollment_id');
        }

        $rows = $enrollments->map(function (Enrollment $enrollment) use ($ledgerTotals) {
            $totals = $ledgerTotals->get($enrollment->id);
            $debits = (float) ($totals->debits ?? 0);
            $credits = (float) ($totals->credits ?? 0);
            return $this->classLedgerStudentRow($enrollment, $debits, $credits);
        })->values();

        $className = $enrollments->first()?->classModel?->name ?? 'N/A';
        $totalDebits = (float) $rows->sum('debits');
        $totalCredits = (float) $rows->sum('credits');

        return [
            'class' => [
                'id' => $classId,
                'name' => $className,
            ],
            'filters' => [
                'academic_year_id' => $request->input('academic_year_id'),
                'start_date' => $request->input('start_date'),
                'end_date' => $request->input('end_date'),
            ],
            'summary' => [
                'students_count' => $rows->count(),
                'total_debits' => round($totalDebits, 2),
                'total_credits' => round($totalCredits, 2),
                'total_balance' => round($totalDebits - $totalCredits, 2),
            ],
            'students' => $rows,
        ];
    }

    private function buildClassLedgerStatementsPayload(Request $request, int $classId): array
    {
        $enrollmentsQuery = Enrollment::with([
            'classModel',
            'section',
            'student.user',
            'student.profile',
            'student.parents.user',
        ])->where('class_id', $classId);

        if ($request->filled('academic_year_id')) {
            $enrollmentsQuery->where('academic_year_id', $request->integer('academic_year_id'));
        }

        $enrollments = $enrollmentsQuery
            ->orderBy('id')
            ->get();

        $enrollmentIds = $enrollments->pluck('id')->all();
        $groupedLedgerEntries = collect();
        $openingBalanceByEnrollmentId = collect();

        if (!empty($enrollmentIds) && $request->filled('start_date')) {
            $openingBalanceByEnrollmentId = DB::table('student_fee_ledger')
                ->select('enrollment_id')
                ->selectRaw("SUM(CASE WHEN transaction_type = 'debit' THEN amount ELSE 0 END) as debits")
                ->selectRaw("SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE 0 END) as credits")
                ->whereIn('enrollment_id', $enrollmentIds)
                ->whereDate('posted_at', '<', $request->date('start_date'))
                ->groupBy('enrollment_id')
                ->get()
                ->mapWithKeys(function ($row) {
                    $opening = (float) ($row->debits ?? 0) - (float) ($row->credits ?? 0);
                    return [$row->enrollment_id => $opening];
                });
        }

        if (!empty($enrollmentIds)) {
            $ledgerQuery = StudentFeeLedger::whereIn('enrollment_id', $enrollmentIds)
                ->orderBy('posted_at')
                ->orderBy('id');

            if ($request->filled('start_date')) {
                $ledgerQuery->whereDate('posted_at', '>=', $request->date('start_date'));
            }
            if ($request->filled('end_date')) {
                $ledgerQuery->whereDate('posted_at', '<=', $request->date('end_date'));
            }

            $groupedLedgerEntries = $ledgerQuery->get()->groupBy('enrollment_id');
        }

        $feeInstallmentReferenceIds = $groupedLedgerEntries
            ->flatten(1)
            ->filter(function (StudentFeeLedger $entry) {
                return $entry->reference_type === 'fee_installment' && !is_null($entry->reference_id);
            })
            ->pluck('reference_id')
            ->unique()
            ->values()
            ->all();

        $feeHeadByAssignmentId = collect();
        if (!empty($feeInstallmentReferenceIds)) {
            $feeHeadByAssignmentId = DB::table('enrollment_fee_installments as efi')
                ->join('fee_installments as fi', 'fi.id', '=', 'efi.fee_installment_id')
                ->join('fee_heads as fh', 'fh.id', '=', 'fi.fee_head_id')
                ->whereIn('efi.id', $feeInstallmentReferenceIds)
                ->pluck('fh.name', 'efi.id');
        }

        $paymentReferenceIds = $groupedLedgerEntries
            ->flatten(1)
            ->filter(function (StudentFeeLedger $entry) {
                return $entry->reference_type === 'payment' && !is_null($entry->reference_id);
            })
            ->pluck('reference_id')
            ->unique()
            ->values()
            ->all();
        $paymentRemarksById = $this->paymentRemarksByReferenceIds($paymentReferenceIds);

        $statements = $enrollments->map(function (Enrollment $enrollment) use ($groupedLedgerEntries, $feeHeadByAssignmentId, $openingBalanceByEnrollmentId, $paymentRemarksById) {
            $entries = $groupedLedgerEntries->get($enrollment->id, collect());
            $runningBalance = (float) ($openingBalanceByEnrollmentId->get($enrollment->id) ?? 0.0);
            $totalDebits = 0.0;
            $totalCredits = 0.0;

            $entryRows = $entries->map(function (StudentFeeLedger $entry) use (&$runningBalance, &$totalDebits, &$totalCredits, $feeHeadByAssignmentId, $paymentRemarksById) {
                $amount = (float) $entry->amount;
                $debit = $entry->transaction_type === 'debit' ? $amount : 0.0;
                $credit = $entry->transaction_type === 'credit' ? $amount : 0.0;
                $referenceLabel = null;
                $referenceNote = null;

                if ($entry->reference_type === 'fee_installment' && !is_null($entry->reference_id)) {
                    $referenceLabel = $feeHeadByAssignmentId->get((int) $entry->reference_id);
                }
                if ($entry->reference_type === 'payment' && !is_null($entry->reference_id)) {
                    $referenceNote = trim((string) ($paymentRemarksById->get((int) $entry->reference_id) ?? '')) ?: null;
                }

                $totalDebits += $debit;
                $totalCredits += $credit;
                $runningBalance += ($debit - $credit);

                return [
                    'id' => $entry->id,
                    'posted_at' => optional($entry->posted_at)->toDateTimeString(),
                    'reference_type' => $entry->reference_type,
                    'reference_id' => $entry->reference_id,
                    'reference_label' => $referenceLabel,
                    'reference_note' => $referenceNote,
                    'transaction_type' => $entry->transaction_type,
                    'debit' => round($debit, 2),
                    'credit' => round($credit, 2),
                    'running_balance' => round($runningBalance, 2),
                    'is_reversal' => (bool) $entry->is_reversal,
                    'narration' => $this->formatLedgerNarration($entry, $paymentRemarksById),
                ];
            })->values();

            return [
                ...$this->classLedgerStudentRow($enrollment, $totalDebits, $totalCredits),
                'totals' => [
                    'debits' => round($totalDebits, 2),
                    'credits' => round($totalCredits, 2),
                    'balance' => round($totalDebits - $totalCredits, 2),
                ],
                'entries' => $entryRows,
            ];
        })->values();

        $className = $enrollments->first()?->classModel?->name ?? 'N/A';
        $totalDebits = (float) $statements->sum('totals.debits');
        $totalCredits = (float) $statements->sum('totals.credits');

        return [
            'class' => [
                'id' => $classId,
                'name' => $className,
            ],
            'filters' => [
                'academic_year_id' => $request->input('academic_year_id'),
                'start_date' => $request->input('start_date'),
                'end_date' => $request->input('end_date'),
            ],
            'summary' => [
                'students_count' => $statements->count(),
                'total_debits' => round($totalDebits, 2),
                'total_credits' => round($totalCredits, 2),
                'total_balance' => round($totalDebits - $totalCredits, 2),
            ],
            'statements' => $statements,
        ];
    }

    private function classLedgerStudentRow(Enrollment $enrollment, float $debits, float $credits): array
    {
        $student = $enrollment->student;
        $user = $student?->user;
        $profile = $student?->profile;
        $fatherFromParents = $student?->parents
            ?->sortByDesc(function ($parent) {
                return (int) ($parent->pivot?->is_primary ?? 0);
            })
            ->first(function ($parent) {
                $relation = strtolower((string) ($parent->pivot?->relation ?? ''));
                return in_array($relation, ['father', 'guardian'], true) || (bool) ($parent->pivot?->is_primary ?? false);
            });

        $studentName = trim((string) (($user?->first_name ?? '') . ' ' . ($user?->last_name ?? '')));
        $fatherName = $profile?->father_name
            ?: trim((string) (($fatherFromParents?->user?->first_name ?? '') . ' ' . ($fatherFromParents?->user?->last_name ?? '')))
            ?: 'N/A';
        $mobileNumber = $profile?->father_mobile_number
            ?? $profile?->father_mobile
            ?? $fatherFromParents?->user?->phone
            ?? $user?->phone
            ?? 'N/A';

        return [
            'ledger_serial_number' => 'LEDGER-' . $enrollment->id,
            'student_id' => $student?->id,
            'student_name' => $studentName ?: 'N/A',
            'admission_number' => $student?->admission_number,
            'father_name' => $fatherName,
            'class' => $enrollment->classModel?->name ?? 'N/A',
            'section' => $enrollment->section?->name,
            'mobile' => $mobileNumber,
            'phone_number' => $mobileNumber,
            'enrollment_id' => $enrollment->id,
            'debits' => round($debits, 2),
            'credits' => round($credits, 2),
            'balance' => round($debits - $credits, 2),
        ];
    }

    private function buildStatementPayload(Student $student, $entries, Request $request, float $openingBalance = 0.0): array
    {
        $runningBalance = $openingBalance;
        $totalDebits = 0.0;
        $totalCredits = 0.0;
        $feeInstallmentReferenceIds = collect($entries)
            ->filter(function (StudentFeeLedger $entry) {
                return $entry->reference_type === 'fee_installment' && !is_null($entry->reference_id);
            })
            ->pluck('reference_id')
            ->unique()
            ->values()
            ->all();
        $feeHeadByAssignmentId = collect();
        if (!empty($feeInstallmentReferenceIds)) {
            $feeHeadByAssignmentId = DB::table('enrollment_fee_installments as efi')
                ->join('fee_installments as fi', 'fi.id', '=', 'efi.fee_installment_id')
                ->join('fee_heads as fh', 'fh.id', '=', 'fi.fee_head_id')
                ->whereIn('efi.id', $feeInstallmentReferenceIds)
                ->pluck('fh.name', 'efi.id');
        }

        $paymentReferenceIds = collect($entries)
            ->filter(function (StudentFeeLedger $entry) {
                return $entry->reference_type === 'payment' && !is_null($entry->reference_id);
            })
            ->pluck('reference_id')
            ->unique()
            ->values()
            ->all();
        $paymentRemarksById = $this->paymentRemarksByReferenceIds($paymentReferenceIds);

        $rows = $entries->map(function (StudentFeeLedger $entry) use (&$runningBalance, &$totalDebits, &$totalCredits, $paymentRemarksById, $feeHeadByAssignmentId) {
            $amount = (float) $entry->amount;
            $debit = $entry->transaction_type === 'debit' ? $amount : 0.0;
            $credit = $entry->transaction_type === 'credit' ? $amount : 0.0;
            $referenceLabel = null;

            if ($entry->reference_type === 'fee_installment' && !is_null($entry->reference_id)) {
                $referenceLabel = $feeHeadByAssignmentId->get((int) $entry->reference_id);
            }

            $totalDebits += $debit;
            $totalCredits += $credit;
            $runningBalance += ($debit - $credit);

            return [
                'id' => $entry->id,
                'posted_at' => optional($entry->posted_at)->toDateTimeString(),
                'enrollment_id' => $entry->enrollment_id,
                'academic_year_id' => $entry->academic_year_id,
                'reference_type' => $entry->reference_type,
                'reference_id' => $entry->reference_id,
                'reference_label' => $referenceLabel,
                'transaction_type' => $entry->transaction_type,
                'debit' => round($debit, 2),
                'credit' => round($credit, 2),
                'running_balance' => round($runningBalance, 2),
                'is_reversal' => (bool) $entry->is_reversal,
                'narration' => $this->formatLedgerNarration($entry, $paymentRemarksById),
            ];
        })->values();

        return [
            'student' => [
                'id' => $student->id,
                'name' => trim((string) (($student->user?->first_name ?? '') . ' ' . ($student->user?->last_name ?? ''))),
                'admission_number' => $student->admission_number,
            ],
            'filters' => [
                'academic_year_id' => $request->input('academic_year_id'),
                'start_date' => $request->input('start_date'),
                'end_date' => $request->input('end_date'),
                'reference_type' => $request->input('reference_type'),
            ],
            'totals' => [
                'opening_balance' => round($openingBalance, 2),
                'debits' => round($totalDebits, 2),
                'credits' => round($totalCredits, 2),
                'balance' => round($totalDebits - $totalCredits, 2),
                'closing_balance' => round($openingBalance + $totalDebits - $totalCredits, 2),
            ],
            'entries' => $rows,
        ];
    }

    private function calculateStudentOpeningBalance(Request $request, int $studentId): float
    {
        if (!$request->filled('start_date')) {
            return 0.0;
        }

        $query = StudentFeeLedger::whereHas('enrollment', function ($q) use ($studentId) {
            $q->where('student_id', $studentId);
        });

        if ($request->filled('academic_year_id')) {
            $yearId = $request->integer('academic_year_id');
            $query->whereHas('enrollment', function ($q) use ($yearId) {
                $q->where('academic_year_id', $yearId);
            });
        }

        if ($request->filled('reference_type')) {
            $query->where('reference_type', (string) $request->input('reference_type'));
        }

        $totals = $query
            ->whereDate('posted_at', '<', $request->date('start_date'))
            ->selectRaw("SUM(CASE WHEN transaction_type = 'debit' THEN amount ELSE 0 END) as debits")
            ->selectRaw("SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE 0 END) as credits")
            ->first();

        $debits = (float) ($totals->debits ?? 0);
        $credits = (float) ($totals->credits ?? 0);

        return $debits - $credits;
    }

    private function paymentRemarksByReferenceIds(array $paymentReferenceIds)
    {
        if (empty($paymentReferenceIds)) {
            return collect();
        }

        return Payment::withTrashed()
            ->whereIn('id', $paymentReferenceIds)
            ->pluck('remarks', 'id');
    }

    private function formatLedgerNarration(StudentFeeLedger $entry, $paymentRemarksById): ?string
    {
        $baseNarration = trim((string) ($entry->narration ?? ''));

        if ($entry->reference_type !== 'payment' || is_null($entry->reference_id)) {
            return $baseNarration !== '' ? $baseNarration : null;
        }

        $remarks = trim((string) ($paymentRemarksById->get((int) $entry->reference_id) ?? ''));
        $prefix = $baseNarration !== '' ? $baseNarration : 'Payment received';

        if ($remarks === '' || stripos($prefix, $remarks) !== false) {
            return $prefix;
        }

        return $prefix . ' | ' . $remarks;
    }
}
