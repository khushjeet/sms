<?php
namespace App\Http\Controllers\Api\FeeFinance;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\FeeInstallment;
use App\Models\StudentFeeInstallment;
use App\Models\StudentFeeLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StudentFeeInstallmentController extends Controller
{
    public function assignToClass(Request $request)
    {
        $data = $request->validate([
            'fee_installment_id' => 'required|exists:fee_installments,id',
            'class_id' => 'required|exists:classes,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'amount' => 'nullable|numeric|min:0',
        ]);

        $installment = FeeInstallment::with(['feeHead'])->findOrFail($data['fee_installment_id']);

        if ((int) $installment->class_id !== (int) $data['class_id']) {
            return response()->json([
                'message' => 'Installment class must match the requested class.'
            ], 422);
        }

        if ((int) $installment->academic_year_id !== (int) $data['academic_year_id']) {
            return response()->json([
                'message' => 'Installment academic year must match the requested academic year.'
            ], 422);
        }

        $actorId = Auth::id();
        $now = now();
        $amount = isset($data['amount']) ? (float) $data['amount'] : (float) $installment->amount;

        $enrollmentsFound = (int) Enrollment::query()
            ->active()
            ->where('academic_year_id', (int) $data['academic_year_id'])
            ->where('class_id', (int) $data['class_id'])
            ->count();
        $assignedCount = 0;

        if ($enrollmentsFound === 0) {
            return response()->json([
                'message' => 'No active enrollments found for this class and academic year.',
                'fee_installment_id' => (int) $installment->id,
                'enrollments_found' => 0,
                'assigned_count' => 0,
            ]);
        }

        Enrollment::query()
            ->active()
            ->where('academic_year_id', (int) $data['academic_year_id'])
            ->where('class_id', (int) $data['class_id'])
            ->orderBy('id')
            ->select(['id'])
            ->chunkById(500, function ($enrollments) use ($installment, $actorId, $now, $amount, &$assignedCount) {
                $enrollmentIds = $enrollments->pluck('id')->all();

                DB::transaction(function () use ($enrollmentIds, $installment, $actorId, $now, $amount, &$assignedCount) {
                    $existingEnrollmentIds = DB::table('enrollment_fee_installments')
                        ->where('fee_installment_id', $installment->id)
                        ->whereIn('enrollment_id', $enrollmentIds)
                        ->pluck('enrollment_id')
                        ->all();

                    $existingSet = array_fill_keys($existingEnrollmentIds, true);

                    $missingEnrollmentIds = array_values(array_filter(
                        $enrollmentIds,
                        fn ($id) => !isset($existingSet[$id])
                    ));

                    if (!empty($missingEnrollmentIds)) {
                        $assignmentRows = [];
                        foreach ($missingEnrollmentIds as $enrollmentId) {
                            $assignmentRows[] = [
                                'enrollment_id' => $enrollmentId,
                                'fee_installment_id' => $installment->id,
                                'amount' => $amount,
                                'assigned_by' => $actorId,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }

                        DB::table('enrollment_fee_installments')->insert($assignmentRows);
                    }

                    // Post missing ledger debits for ANY obligations in this chunk (covers retries where
                    // obligations were created but ledger insertion failed previously).
                    $assignmentsMissingLedger = DB::table('enrollment_fee_installments as efi')
                        ->leftJoin('student_fee_ledger as sfl', function ($join) {
                            $join->on('sfl.reference_id', '=', 'efi.id')
                                ->where('sfl.reference_type', '=', 'fee_installment')
                                ->where('sfl.transaction_type', '=', 'debit');
                        })
                        ->whereNull('sfl.id')
                        ->where('efi.fee_installment_id', $installment->id)
                        ->whereIn('efi.enrollment_id', $enrollmentIds)
                        ->select(['efi.id', 'efi.enrollment_id', 'efi.amount'])
                        ->get();

                    $ledgerRows = [];
                    foreach ($assignmentsMissingLedger as $assignment) {
                        $ledgerRows[] = [
                            'enrollment_id' => $assignment->enrollment_id,
                            'transaction_type' => 'debit',
                            'reference_type' => 'fee_installment',
                            'reference_id' => $assignment->id,
                            'amount' => $assignment->amount,
                            'posted_by' => $actorId,
                            'posted_at' => $now,
                            'narration' => sprintf('Installment assigned: %s', $installment->name),
                            'is_reversal' => false,
                            'reversal_of' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    if (!empty($ledgerRows)) {
                        DB::table('student_fee_ledger')->insert($ledgerRows);
                    }

                    $assignedCount += count($assignmentsMissingLedger);
                });
            });

        // Note: we intentionally return "assigned_count" as the number of new charges posted.

        AuditLog::log('create', $installment, null, [
            'fee_installment_id' => (int) $installment->id,
            'class_id' => (int) $installment->class_id,
            'academic_year_id' => (int) $installment->academic_year_id,
            'amount' => $amount,
            'assigned_count' => $assignedCount,
        ], 'Bulk installment assigned to class');

        return response()->json([
            'message' => $assignedCount > 0
                ? 'Installment assigned to class successfully.'
                : 'No new charges posted (all enrollments already assigned).',
            'fee_installment_id' => (int) $installment->id,
            'enrollments_found' => $enrollmentsFound,
            'assigned_count' => $assignedCount,
        ]);
    }

    public function index(Request $request, $studentId)
    {
        $query = StudentFeeInstallment::with(['feeInstallment.feeHead', 'enrollment.academicYear'])
            ->whereHas('enrollment', function ($q) use ($studentId) {
                $q->where('student_id', $studentId);
            });

        if ($request->filled('academic_year_id')) {
            $yearId = $request->integer('academic_year_id');
            $query->whereHas('enrollment', function ($q) use ($yearId) {
                $q->where('academic_year_id', $yearId);
            });
        }

        return $query->orderByDesc('id')->get();
    }

    public function store(Request $request, $studentId)
    {
        $data = $request->validate([
            'fee_installment_id' => 'required|exists:fee_installments,id',
            'amount' => 'nullable|numeric|min:0',
            'academic_year_id' => 'nullable|exists:academic_years,id',
            'enrollment_id' => 'nullable|exists:enrollments,id',
        ]);

        $installment = FeeInstallment::findOrFail($data['fee_installment_id']);

        if (isset($data['academic_year_id']) && (int) $data['academic_year_id'] !== (int) $installment->academic_year_id) {
            return response()->json([
                'message' => 'Academic year does not match the installment year.'
            ], 422);
        }

        $enrollmentId = $data['enrollment_id'] ?? null;

        if ($enrollmentId) {
            $enrollment = Enrollment::findOrFail($enrollmentId);
            if ((int) $enrollment->student_id !== (int) $studentId) {
                return response()->json([
                    'message' => 'Enrollment does not belong to this student.'
                ], 422);
            }
        } else {
            $enrollment = Enrollment::where('student_id', (int) $studentId)
                ->where('academic_year_id', (int) $installment->academic_year_id)
                ->first();

            if (!$enrollment) {
                return response()->json([
                    'message' => 'No enrollment found for this student in the installment academic year.'
                ], 422);
            }
        }

        return $this->assignInstallmentToEnrollment($enrollment, $installment, $data['amount'] ?? null);
    }

    public function storeByEnrollment(Request $request, $enrollmentId)
    {
        $data = $request->validate([
            'fee_installment_id' => 'required|exists:fee_installments,id',
            'amount' => 'nullable|numeric|min:0',
        ]);

        $enrollment = Enrollment::findOrFail($enrollmentId);
        $installment = FeeInstallment::findOrFail($data['fee_installment_id']);

        if ($enrollment->status !== 'active' || $enrollment->is_locked) {
            return response()->json([
                'message' => 'Only active, unlocked enrollments can receive new installment assignments.'
            ], 422);
        }

        if ((int) $enrollment->academic_year_id !== (int) $installment->academic_year_id) {
            return response()->json([
                'message' => 'Installment academic year must match enrollment academic year.'
            ], 422);
        }

        if ($enrollment->class_id && (int) $enrollment->class_id !== (int) $installment->class_id) {
            return response()->json([
                'message' => 'Installment class must match enrollment class.'
            ], 422);
        }

        $response = $this->assignInstallmentToEnrollment($enrollment, $installment, $data['amount'] ?? null);
        $payload = $response->getData(true);
        $payload['enrollment_id'] = (int) $enrollment->id;

        return response()->json($payload, $response->getStatusCode());
    }

    private function assignInstallmentToEnrollment(Enrollment $enrollment, FeeInstallment $installment, ?float $amount = null)
    {
        $payload = [
            'enrollment_id' => $enrollment->id,
            'fee_installment_id' => $installment->id,
            'amount' => $amount ?? $installment->amount,
            'assigned_by' => Auth::id(),
        ];

        $existing = StudentFeeInstallment::where('enrollment_id', $payload['enrollment_id'])
            ->where('fee_installment_id', $payload['fee_installment_id'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Installment already assigned to this student.'
            ], 409);
        }

        return DB::transaction(function () use ($payload, $installment) {
            $assignment = StudentFeeInstallment::create($payload);

            $ledger = StudentFeeLedger::create([
                'enrollment_id' => $payload['enrollment_id'],
                'transaction_type' => 'debit',
                'reference_type' => 'fee_installment',
                'reference_id' => $assignment->id,
                'amount' => $payload['amount'],
                'posted_by' => $payload['assigned_by'],
                'posted_at' => now(),
                'narration' => sprintf('Installment assigned: %s', $installment->name),
                'is_reversal' => false,
            ]);

            AuditLog::log('create', $assignment, null, $assignment->toArray(), 'Student fee installment assigned');
            AuditLog::log('create', $ledger, null, $ledger->toArray(), 'Ledger debit created from installment');

            return response()->json([
                'assignment' => $assignment,
                'ledger_entry' => $ledger,
            ], 201);
        });
    }
}
