<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Models\TeacherDocument;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeController extends Controller
{
    private const USER_ROLES = ['super_admin', 'school_admin', 'accountant', 'teacher', 'parent', 'student', 'staff'];
    private const EMPLOYEE_TYPES = ['teaching', 'non_teaching'];
    private const GENDERS = ['male', 'female', 'other'];
    private const STAFF_STATUSES = ['active', 'on_leave', 'resigned', 'terminated'];
    private const DOCUMENT_TYPES = ['resume', 'identity', 'certificate', 'pan_card', 'other'];

    public function metadata()
    {
        $designationOptions = Staff::query()
            ->select('designation')
            ->whereNotNull('designation')
            ->distinct()
            ->orderBy('designation')
            ->pluck('designation')
            ->values();

        $departmentOptions = Staff::query()
            ->select('department')
            ->whereNotNull('department')
            ->distinct()
            ->orderBy('department')
            ->pluck('department')
            ->values();

        if ($designationOptions->isEmpty()) {
            $designationOptions = collect(['Teacher', 'Accountant', 'Librarian', 'Principal', 'Clerk']);
        }
        if ($departmentOptions->isEmpty()) {
            $departmentOptions = collect(['Academic', 'Administration', 'Finance', 'Transport', 'Library']);
        }

        return response()->json([
            'roles' => self::USER_ROLES,
            'employee_types' => self::EMPLOYEE_TYPES,
            'genders' => self::GENDERS,
            'statuses' => self::STAFF_STATUSES,
            'document_types' => self::DOCUMENT_TYPES,
            'designation_options' => $designationOptions,
            'department_options' => $departmentOptions,
        ]);
    }

    public function index(Request $request)
    {
        $query = Staff::query()->with(['user', 'documents']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('employee_type')) {
            $query->where('employee_type', $request->input('employee_type'));
        }

        if ($request->filled('role')) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->byRole($request->input('role'));
            });
        }

        if ($request->filled('designation')) {
            $query->where('designation', 'like', '%' . trim((string) $request->input('designation')) . '%');
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('employee_id', 'like', "%{$search}%")
                    ->orWhere('pan_number', 'like', "%{$search}%")
                    ->orWhere('aadhar_number', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
            });
        }

        return response()->json($query->paginate((int) $request->input('per_page', 15)));
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);
        $panNumber = $validated['pan_number'] ?? $validated['pan_card'] ?? null;

        return DB::transaction(function () use ($request, $validated, $panNumber) {
            $user = User::create([
                'email' => $validated['email'],
                'password' => Hash::make($validated['password'] ?? 'default@123'),
                'role' => $validated['role'],
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'phone' => $validated['phone'] ?? null,
                'status' => 'active',
            ]);
            $user->assignRole($validated['role'], $request->user()?->id);

            if ($request->hasFile('image')) {
                $user->avatar = $request->file('image')->store('employees/avatars', 'public');
                $user->save();
            }

            $staff = Staff::create([
                'user_id' => $user->id,
                'employee_id' => $validated['employee_id'],
                'joining_date' => $validated['joining_date'],
                'employee_type' => $validated['employee_type'],
                'designation' => $validated['designation'] ?? 'Teacher',
                'department' => $validated['department'] ?? null,
                'qualification' => $validated['qualification'] ?? null,
                'salary' => $validated['salary'] ?? null,
                'date_of_birth' => $validated['date_of_birth'],
                'gender' => $validated['gender'],
                'address' => $validated['address'] ?? null,
                'emergency_contact' => $validated['emergency_contact'] ?? null,
                'aadhar_number' => $validated['aadhar_number'] ?? null,
                'pan_number' => $panNumber,
                'status' => $validated['status'] ?? 'active',
                'resignation_date' => $validated['resignation_date'] ?? null,
            ]);

            $this->storeDocuments($request, $staff);

            return response()->json([
                'message' => 'Employee profile created successfully',
                'data' => $staff->load(['user', 'documents']),
            ], 201);
        });
    }

    public function show(int $id)
    {
        return response()->json(Staff::with(['user', 'documents'])->findOrFail($id));
    }

    public function update(Request $request, int $id)
    {
        $staff = Staff::with('user')->findOrFail($id);
        $validated = $this->validatePayload($request, $staff);
        $panNumber = $validated['pan_number'] ?? $validated['pan_card'] ?? null;

        return DB::transaction(function () use ($request, $staff, $validated, $panNumber) {
            $userPayload = [];
            foreach (['first_name', 'last_name', 'phone', 'role'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $userPayload[$field] = $validated[$field];
                }
            }
            if (array_key_exists('email', $validated)) {
                $userPayload['email'] = $validated['email'];
            }
            if (!empty($validated['password'])) {
                $userPayload['password'] = Hash::make((string) $validated['password']);
            }
            if (!empty($userPayload)) {
                $staff->user->update($userPayload);
                if (array_key_exists('role', $userPayload)) {
                    $staff->user->assignRole((string) $userPayload['role'], $request->user()?->id);
                }
            }

            if ($request->hasFile('image')) {
                if ($staff->user->avatar) {
                    Storage::disk('public')->delete($staff->user->avatar);
                }
                $staff->user->avatar = $request->file('image')->store('employees/avatars', 'public');
                $staff->user->save();
            }

            $staffPayload = [];
            foreach ([
                'employee_id', 'joining_date', 'employee_type', 'designation', 'department',
                'qualification', 'salary', 'date_of_birth', 'gender', 'address',
                'emergency_contact', 'aadhar_number', 'status', 'resignation_date',
            ] as $field) {
                if (array_key_exists($field, $validated)) {
                    $staffPayload[$field] = $validated[$field];
                }
            }
            if ($panNumber !== null) {
                $staffPayload['pan_number'] = $panNumber;
            }
            if (!empty($staffPayload)) {
                $staff->update($staffPayload);
            }

            $this->storeDocuments($request, $staff);

            return response()->json([
                'message' => 'Employee profile updated successfully',
                'data' => $staff->fresh()->load(['user', 'documents']),
            ]);
        });
    }

    public function destroy(int $id)
    {
        $staff = Staff::with('user')->findOrFail($id);

        DB::transaction(function () use ($staff) {
            $staff->update([
                'status' => 'resigned',
                'resignation_date' => now()->toDateString(),
            ]);
            $staff->delete();
            if ($staff->user) {
                $staff->user->update(['status' => 'inactive']);
            }
        });

        return response()->json(['message' => 'Employee profile archived successfully']);
    }

    public function uploadDocument(Request $request, int $id)
    {
        $staff = Staff::findOrFail($id);
        $request->validate([
            'documents' => 'required|array|min:1',
            'documents.*' => 'file|mimes:pdf,doc,docx,jpg,jpeg,png,webp|max:10240',
            'document_types' => 'nullable|array',
            'document_types.*' => 'nullable|in:resume,identity,certificate,pan_card,other',
        ]);

        $this->storeDocuments($request, $staff);

        return response()->json([
            'message' => 'Documents uploaded successfully',
            'data' => $staff->fresh()->load('documents'),
        ], 201);
    }

    public function documentFile(int $id, int $documentId)
    {
        $staff = Staff::findOrFail($id);
        $document = TeacherDocument::where('staff_id', $staff->id)->findOrFail($documentId);

        if (!Storage::disk('public')->exists($document->file_path)) {
            return response()->json(['message' => 'Document file not found'], 404);
        }

        return response()->file(Storage::disk('public')->path($document->file_path));
    }

    public function attendanceHistory(Request $request, int $id)
    {
        $staff = Staff::findOrFail($id);
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', Rule::in(['present', 'absent', 'half_day', 'leave'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $query = DB::table('staff_attendance_records')
            ->where('staff_id', $staff->id)
            ->orderByDesc('attendance_date')
            ->orderByDesc('id');

        if (!empty($validated['start_date'])) {
            $query->whereDate('attendance_date', '>=', $validated['start_date']);
        }
        if (!empty($validated['end_date'])) {
            $query->whereDate('attendance_date', '<=', $validated['end_date']);
        }
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return response()->json($query->paginate((int) ($validated['per_page'] ?? 60)));
    }

    public function attendanceHistoryDownload(Request $request, int $id): StreamedResponse
    {
        $staff = Staff::with('user')->findOrFail($id);
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', Rule::in(['present', 'absent', 'half_day', 'leave'])],
        ]);

        $query = DB::table('staff_attendance_records')
            ->where('staff_id', $staff->id)
            ->orderBy('attendance_date')
            ->orderBy('id');

        if (!empty($validated['start_date'])) {
            $query->whereDate('attendance_date', '>=', $validated['start_date']);
        }
        if (!empty($validated['end_date'])) {
            $query->whereDate('attendance_date', '<=', $validated['end_date']);
        }
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $rows = $query->get();

        $filename = 'staff_attendance_' . $staff->employee_id . '_' . now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($staff, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'staff_id',
                'employee_id',
                'staff_name',
                'date',
                'status',
                'late_minutes',
                'remarks',
                'override_reason',
                'created_at',
                'updated_at',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $staff->id,
                    $staff->employee_id,
                    $staff->user?->full_name,
                    $row->attendance_date,
                    $row->status,
                    $row->late_minutes,
                    $row->remarks,
                    $row->override_reason,
                    $row->created_at,
                    $row->updated_at,
                ]);
            }

            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function payoutHistory(Request $request, int $id)
    {
        $staff = Staff::findOrFail($id);
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'status' => ['nullable', Rule::in(['generated', 'finalized', 'paid'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:120'],
        ]);

        $query = DB::table('payroll_batch_items as i')
            ->join('payroll_batches as b', 'b.id', '=', 'i.payroll_batch_id')
            ->select(
                'i.id',
                'i.staff_id',
                'i.payroll_batch_id',
                'i.days_in_month',
                'i.payable_days',
                'i.leave_days',
                'i.absent_days',
                'i.gross_pay',
                'i.total_deductions',
                'i.net_pay',
                'i.snapshot',
                'b.year',
                'b.month',
                'b.status',
                'b.generated_at',
                'b.finalized_at',
                'b.paid_at'
            )
            ->where('i.staff_id', $staff->id)
            ->orderByDesc('b.year')
            ->orderByDesc('b.month')
            ->orderByDesc('i.id');

        if (!empty($validated['year'])) {
            $query->where('b.year', (int) $validated['year']);
        }
        if (!empty($validated['month'])) {
            $query->where('b.month', (int) $validated['month']);
        }
        if (!empty($validated['status'])) {
            $query->where('b.status', $validated['status']);
        }

        $page = $query->paginate((int) ($validated['per_page'] ?? 36));
        $itemIds = collect($page->items())->pluck('id')->map(fn ($idValue) => (int) $idValue)->all();
        $adjustmentMap = DB::table('payroll_item_adjustments')
            ->selectRaw('payroll_batch_item_id, COALESCE(SUM(amount), 0) as adjustment_total')
            ->whereIn('payroll_batch_item_id', $itemIds)
            ->groupBy('payroll_batch_item_id')
            ->pluck('adjustment_total', 'payroll_batch_item_id');

        $transformed = collect($page->items())->map(function ($row) use ($adjustmentMap) {
            $adjustmentTotal = (float) ($adjustmentMap[(int) $row->id] ?? 0);
            return [
                'id' => $row->id,
                'staff_id' => $row->staff_id,
                'payroll_batch_id' => $row->payroll_batch_id,
                'year' => $row->year,
                'month' => $row->month,
                'status' => $row->status,
                'days_in_month' => $row->days_in_month,
                'payable_days' => $row->payable_days,
                'leave_days' => $row->leave_days,
                'absent_days' => $row->absent_days,
                'gross_pay' => $row->gross_pay,
                'total_deductions' => $row->total_deductions,
                'net_pay' => $row->net_pay,
                'adjustment_total' => number_format($adjustmentTotal, 2, '.', ''),
                'net_after_adjustment' => number_format(((float) $row->net_pay) + $adjustmentTotal, 2, '.', ''),
                'snapshot' => json_decode((string) $row->snapshot, true),
                'generated_at' => $row->generated_at,
                'finalized_at' => $row->finalized_at,
                'paid_at' => $row->paid_at,
            ];
        })->values();

        return response()->json([
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'data' => $transformed,
        ]);
    }

    public function payoutHistoryDownload(Request $request, int $id): StreamedResponse
    {
        $staff = Staff::with('user')->findOrFail($id);
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'status' => ['nullable', Rule::in(['generated', 'finalized', 'paid'])],
        ]);

        $query = DB::table('payroll_batch_items as i')
            ->join('payroll_batches as b', 'b.id', '=', 'i.payroll_batch_id')
            ->select(
                'i.id',
                'i.staff_id',
                'i.payroll_batch_id',
                'i.days_in_month',
                'i.payable_days',
                'i.leave_days',
                'i.absent_days',
                'i.gross_pay',
                'i.total_deductions',
                'i.net_pay',
                'b.year',
                'b.month',
                'b.status',
                'b.generated_at',
                'b.finalized_at',
                'b.paid_at'
            )
            ->where('i.staff_id', $staff->id)
            ->orderBy('b.year')
            ->orderBy('b.month')
            ->orderBy('i.id');

        if (!empty($validated['year'])) {
            $query->where('b.year', (int) $validated['year']);
        }
        if (!empty($validated['month'])) {
            $query->where('b.month', (int) $validated['month']);
        }
        if (!empty($validated['status'])) {
            $query->where('b.status', $validated['status']);
        }

        $rows = $query->get();
        $itemIds = $rows->pluck('id')->map(fn ($value) => (int) $value)->all();
        $adjustmentMap = DB::table('payroll_item_adjustments')
            ->selectRaw('payroll_batch_item_id, COALESCE(SUM(amount), 0) as adjustment_total')
            ->whereIn('payroll_batch_item_id', $itemIds)
            ->groupBy('payroll_batch_item_id')
            ->pluck('adjustment_total', 'payroll_batch_item_id');

        $filename = 'staff_payout_' . $staff->employee_id . '_' . now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($staff, $rows, $adjustmentMap) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'staff_id',
                'employee_id',
                'staff_name',
                'batch_item_id',
                'batch_id',
                'year',
                'month',
                'status',
                'days_in_month',
                'payable_days',
                'leave_days',
                'absent_days',
                'gross_pay',
                'total_deductions',
                'net_pay',
                'adjustment_total',
                'net_after_adjustment',
                'generated_at',
                'finalized_at',
                'paid_at',
            ]);

            foreach ($rows as $row) {
                $adjustmentTotal = (float) ($adjustmentMap[(int) $row->id] ?? 0);
                fputcsv($out, [
                    $staff->id,
                    $staff->employee_id,
                    $staff->user?->full_name,
                    $row->id,
                    $row->payroll_batch_id,
                    $row->year,
                    $row->month,
                    $row->status,
                    $row->days_in_month,
                    $row->payable_days,
                    $row->leave_days,
                    $row->absent_days,
                    $row->gross_pay,
                    $row->total_deductions,
                    $row->net_pay,
                    number_format($adjustmentTotal, 2, '.', ''),
                    number_format(((float) $row->net_pay) + $adjustmentTotal, 2, '.', ''),
                    $row->generated_at,
                    $row->finalized_at,
                    $row->paid_at,
                ]);
            }

            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }

    private function validatePayload(Request $request, ?Staff $staff = null): array
    {
        $staffId = $staff?->id;
        $userId = $staff?->user_id;

        $commonRules = [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', Rule::in(self::USER_ROLES)],
            'password' => [$staff ? 'nullable' : 'required', 'string', 'min:8'],
            'employee_id' => ['required', 'string', 'max:50', Rule::unique('staff', 'employee_id')->ignore($staffId)],
            'joining_date' => ['required', 'date'],
            'employee_type' => ['required', Rule::in(self::EMPLOYEE_TYPES)],
            'designation' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'qualification' => ['nullable', 'string', 'max:255'],
            'salary' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'date_of_birth' => ['required', 'date'],
            'gender' => ['required', Rule::in(self::GENDERS)],
            'address' => ['nullable', 'string'],
            'emergency_contact' => ['nullable', 'string', 'max:50'],
            'aadhar_number' => ['nullable', 'string', 'max:20', Rule::unique('staff', 'aadhar_number')->ignore($staffId)],
            'pan_number' => ['nullable', 'string', 'max:20', Rule::unique('staff', 'pan_number')->ignore($staffId)],
            'pan_card' => ['nullable', 'string', 'max:20', Rule::unique('staff', 'pan_number')->ignore($staffId)],
            'status' => ['nullable', Rule::in(self::STAFF_STATUSES)],
            'resignation_date' => ['nullable', 'date'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'documents' => ['nullable', 'array'],
            'documents.*' => ['file', 'mimes:pdf,doc,docx,jpg,jpeg,png,webp', 'max:10240'],
            'document_types' => ['nullable', 'array'],
            'document_types.*' => ['nullable', Rule::in(self::DOCUMENT_TYPES)],
        ];

        if ($staff) {
            return $request->validate($this->toSometimesRules($commonRules));
        }

        return $request->validate($commonRules);
    }

    private function toSometimesRules(array $rules): array
    {
        $sometimesRules = [];
        foreach ($rules as $field => $ruleSet) {
            $sometimesRules[$field] = array_merge(['sometimes'], $ruleSet);
        }

        return $sometimesRules;
    }

    private function storeDocuments(Request $request, Staff $staff): void
    {
        $files = $request->file('documents', []);
        if (empty($files)) {
            return;
        }

        $types = $request->input('document_types', []);

        foreach ($files as $index => $file) {
            $extension = strtolower((string) $file->getClientOriginalExtension());
            $storedName = Str::uuid()->toString() . ($extension ? ".{$extension}" : '');
            $path = $file->storeAs('employees/documents/' . now()->format('Y/m'), $storedName, 'public');
            $documentType = $types[$index] ?? 'other';

            if (!in_array($documentType, self::DOCUMENT_TYPES, true)) {
                $documentType = 'other';
            }

            TeacherDocument::create([
                'staff_id' => $staff->id,
                'document_type' => $documentType,
                'file_name' => $storedName,
                'original_name' => (string) $file->getClientOriginalName(),
                'mime_type' => (string) $file->getClientMimeType(),
                'extension' => $extension ?: null,
                'size_bytes' => (int) $file->getSize(),
                'sha256' => @hash_file('sha256', $file->getRealPath()) ?: null,
                'file_path' => $path,
                'uploaded_by' => $request->user()?->id,
            ]);
        }
    }
}
