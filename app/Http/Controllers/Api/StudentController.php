<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ParentModel;
use App\Models\Student;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class StudentController extends Controller
{
    /**
     * Display a listing of students
     */
    public function index(Request $request)
    {
        $query = Student::with([
            'user',
            'currentEnrollment.section.class',
            'currentEnrollment.classModel',
            'profile.class',
            'profile.academicYear',
        ]);

        // Filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('class_id')) {
            $query->whereHas('currentEnrollment.section', function ($q) use ($request) {
                $q->where('class_id', $request->class_id);
            });
        }

        if ($request->has('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('admission_number', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });

                if (is_numeric($search)) {
                    $enrollmentId = (int) $search;
                    $q->orWhereHas('enrollments', function ($eq) use ($enrollmentId) {
                        $eq->where('id', $enrollmentId);
                    });
                }
            });
        }

        $students = $query->paginate($request->per_page ?? 15);

        return response()->json($students);
    }

    /**
     * Store a newly created student
     */
public function store(Request $request)
{
    $validated = $request->validate([
        'first_name' => 'required|string|max:255',
        'last_name' => 'required|string|max:255',
        'email' => 'required|email',
        'phone' => 'nullable|string|max:20',
        'password' => 'nullable|string|min:8',

        'admission_number' => 'required|string|unique:students,admission_number',
        'admission_date' => 'required|date',
        'date_of_birth' => 'required|date',
        'gender' => 'required|in:male,female,other',
        'blood_group' => 'nullable|string',
        'address' => 'nullable|string',
        'city' => 'nullable|string',
        'state' => 'nullable|string',
        'pincode' => 'nullable|string',
        'nationality' => 'nullable|string',
        'religion' => 'nullable|string',
        'category' => 'nullable|string',
        'caste' => 'nullable|string|max:255',
        'aadhar_number' => 'nullable|string|unique:students,aadhar_number',
        'medical_info' => 'nullable',
        'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        'academic_year_id' => 'nullable|exists:academic_years,id',
        'class_id' => 'nullable|exists:classes,id',
        'roll_number' => 'nullable|string|max:50',
        'father_name' => 'nullable|string|max:255',
        'father_email' => 'nullable|email|max:255',
        'father_mobile' => 'nullable|string|max:20',
        'father_mobile_number' => 'nullable|string|max:20',
        'father_occupation' => 'nullable|string|max:255',
        'mother_name' => 'nullable|string|max:255',
        'mother_email' => 'nullable|email|max:255',
        'mother_mobile' => 'nullable|string|max:20',
        'mother_mobile_number' => 'nullable|string|max:20',
        'mother_occupation' => 'nullable|string|max:255',
        'bank_account_number' => 'nullable|string|max:50',
        'bank_account_holder' => 'nullable|string|max:255',
        'ifsc_code' => 'nullable|string|max:20',
        'relation_with_account_holder' => 'nullable|string|max:255',
        'permanent_address' => 'nullable|string',
        'current_address' => 'nullable|string',
    ]);

    try {
        DB::beginTransaction();

        // 🔍 Find existing user by email
        $user = User::where('email', $validated['email'])->first();

        // 🆕 Create user only if not exists
        if (!$user) {
            $user = User::create([
                'email' => $validated['email'],
                'password' => Hash::make($validated['password'] ?? 'default@123'),
                'role' => 'student',
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'phone' => $validated['phone'] ?? null,
                'status' => 'active',
            ]);
        } else {
            // 🔄 Upgrade role if needed
            if ($user->role !== 'student') {
                $user->update(['role' => 'student']);
            }
        }
        $user->assignRole('student', $request->user()?->id);

        if ($request->hasFile('image')) {
            if ($user->avatar) {
                Storage::disk('public')->delete($user->avatar);
            }
            $user->avatar = $request->file('image')->store('students/avatars', 'public');
            $user->save();
        }
        $avatarUrl = $user->avatar ?? null;

        // ❌ Prevent duplicate student enrollment
        if (Student::where('user_id', $user->id)->exists()) {
            return response()->json([
                'message' => 'This user is already enrolled as a student'
            ], 409);
        }

        // 🎓 Create student record
        $student = Student::create([
            'user_id' => $user->id,
            'admission_number' => $validated['admission_number'],
            'admission_date' => $validated['admission_date'],
            'date_of_birth' => $validated['date_of_birth'],
            'gender' => $validated['gender'],
            'blood_group' => $validated['blood_group'] ?? null,
            'address' => $validated['address'] ?? null,
            'city' => $validated['city'] ?? null,
            'state' => $validated['state'] ?? null,
            'pincode' => $validated['pincode'] ?? null,
            'nationality' => $validated['nationality'] ?? 'Indian',
            'religion' => $validated['religion'] ?? null,
            'category' => $validated['category'] ?? null,
            'aadhar_number' => $validated['aadhar_number'] ?? null,
            'medical_info' => $this->normalizeMedicalInfo($validated['medical_info'] ?? null),
            'avatar_url' => $avatarUrl,
            'status' => 'active',
        ]);

        $fatherMobile = $validated['father_mobile_number'] ?? $validated['father_mobile'] ?? null;
        $motherMobile = $validated['mother_mobile_number'] ?? $validated['mother_mobile'] ?? null;

        StudentProfile::create([
            'student_id' => $student->id,
            'user_id' => $user->id,
            'avatar_url' => $avatarUrl,
            'academic_year_id' => $validated['academic_year_id'] ?? null,
            'class_id' => $validated['class_id'] ?? null,
            'roll_number' => $validated['roll_number'] ?? null,
            'caste' => $validated['caste'] ?? null,
            'father_name' => $validated['father_name'] ?? null,
            'father_email' => $validated['father_email'] ?? null,
            'father_mobile' => $fatherMobile,
            'father_mobile_number' => $fatherMobile,
            'father_occupation' => $validated['father_occupation'] ?? null,
            'mother_name' => $validated['mother_name'] ?? null,
            'mother_email' => $validated['mother_email'] ?? null,
            'mother_mobile' => $motherMobile,
            'mother_mobile_number' => $motherMobile,
            'mother_occupation' => $validated['mother_occupation'] ?? null,
            'bank_account_number' => $validated['bank_account_number'] ?? null,
            'bank_account_holder' => $validated['bank_account_holder'] ?? null,
            'ifsc_code' => $validated['ifsc_code'] ?? null,
            'relation_with_account_holder' => $validated['relation_with_account_holder'] ?? null,
            'permanent_address' => $validated['permanent_address'] ?? null,
            'current_address' => $validated['current_address'] ?? null,
        ]);

        $this->createOrAttachParent(
            $student,
            relation: 'father',
            name: $validated['father_name'] ?? null,
            email: $validated['father_email'] ?? null,
            mobile: $fatherMobile,
            occupation: $validated['father_occupation'] ?? null,
            isPrimary: true
        );

        $this->createOrAttachParent(
            $student,
            relation: 'mother',
            name: $validated['mother_name'] ?? null,
            email: $validated['mother_email'] ?? null,
            mobile: $motherMobile,
            occupation: $validated['mother_occupation'] ?? null,
            isPrimary: false
        );

        DB::commit();

        return response()->json([
            'message' => 'Student enrolled successfully',
            'data' => $student->load(['user', 'profile.academicYear', 'profile.class'])
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();
        report($e);
        return response()->json([
            'message' => 'Failed to enroll student'
        ], 500);
    }
}


    /**
     * Display the specified student
     */
    public function show($id)
    {
        $student = Student::with([
            'user',
            'parents.user',
            'profile.academicYear',
            'profile.class',
            'currentEnrollment.section.class',
            'currentEnrollment.classModel',
            'currentEnrollment.feeAssignment',
            'financialHolds' => function ($q) {
                $q->where('is_active', true);
            }
        ])->findOrFail($id);

        return response()->json($student);
    }

    /**
     * Update the specified student
     */
    public function update(Request $request, $id)
    {
        $student = Student::findOrFail($id);

        $validated = $request->validate([
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', Rule::unique('users')->ignore($student->user_id)],
            'phone' => 'nullable|string|max:20',
            'password' => 'nullable|string|min:8|confirmed',
            'blood_group' => 'nullable|string',
            'address' => 'nullable|string',
            'city' => 'nullable|string',
            'state' => 'nullable|string',
            'pincode' => 'nullable|string',
            'medical_info' => 'nullable',
            'remarks' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'caste' => 'nullable|string|max:255',
            'academic_year_id' => 'nullable|exists:academic_years,id',
            'class_id' => 'nullable|exists:classes,id',
            'roll_number' => 'nullable|string|max:50',
            'father_name' => 'nullable|string|max:255',
            'father_email' => 'nullable|email|max:255',
            'father_mobile' => 'nullable|string|max:20',
            'father_mobile_number' => 'nullable|string|max:20',
            'father_occupation' => 'nullable|string|max:255',
            'mother_name' => 'nullable|string|max:255',
            'mother_email' => 'nullable|email|max:255',
            'mother_mobile' => 'nullable|string|max:20',
            'mother_mobile_number' => 'nullable|string|max:20',
            'mother_occupation' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:50',
            'bank_account_holder' => 'nullable|string|max:255',
            'ifsc_code' => 'nullable|string|max:20',
            'relation_with_account_holder' => 'nullable|string|max:255',
            'permanent_address' => 'nullable|string',
            'current_address' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            // Update user if user fields are present
            if (isset($validated['email']) || isset($validated['first_name']) ||
                isset($validated['last_name']) || isset($validated['phone']) ||
                isset($validated['password'])) {
                $userPayload = [
                    'email' => $validated['email'] ?? $student->user->email,
                    'first_name' => $validated['first_name'] ?? $student->user->first_name,
                    'last_name' => $validated['last_name'] ?? $student->user->last_name,
                    'phone' => $validated['phone'] ?? $student->user->phone,
                ];
                if (!empty($validated['password'])) {
                    $userPayload['password'] = Hash::make($validated['password']);
                }
                $student->user->update($userPayload);
            }

            if ($request->hasFile('image')) {
                if ($student->user->avatar) {
                    Storage::disk('public')->delete($student->user->avatar);
                }
                $student->user->avatar = $request->file('image')->store('students/avatars', 'public');
                $student->user->save();
                $student->avatar_url = $student->user->avatar;
                $student->save();
                $student->profile()->updateOrCreate(
                    ['student_id' => $student->id],
                    [
                        'user_id' => $student->user_id,
                        'avatar_url' => $student->user->avatar,
                    ]
                );
            }

            // Update student record
            $student->update(array_intersect_key($validated, array_flip([
                'blood_group', 'address', 'city', 'state', 'pincode', 'medical_info', 'remarks'
            ])));

            if (array_key_exists('medical_info', $validated)) {
                $student->medical_info = $this->normalizeMedicalInfo($validated['medical_info']);
                $student->save();
            }

            $profilePayload = array_intersect_key($validated, array_flip([
                'academic_year_id', 'class_id', 'roll_number', 'caste',
                'father_name', 'father_email', 'father_mobile', 'father_occupation',
                'mother_name', 'mother_email', 'mother_mobile', 'mother_occupation',
                'bank_account_number', 'bank_account_holder', 'ifsc_code',
                'relation_with_account_holder', 'permanent_address', 'current_address',
            ]));

            if (array_key_exists('father_mobile_number', $validated)) {
                $profilePayload['father_mobile_number'] = $validated['father_mobile_number'];
                $profilePayload['father_mobile'] = $validated['father_mobile_number'];
            } elseif (array_key_exists('father_mobile', $validated)) {
                $profilePayload['father_mobile_number'] = $validated['father_mobile'];
            }

            if (array_key_exists('mother_mobile_number', $validated)) {
                $profilePayload['mother_mobile_number'] = $validated['mother_mobile_number'];
                $profilePayload['mother_mobile'] = $validated['mother_mobile_number'];
            } elseif (array_key_exists('mother_mobile', $validated)) {
                $profilePayload['mother_mobile_number'] = $validated['mother_mobile'];
            }

            if (!empty($profilePayload)) {
                $profilePayload['user_id'] = $student->user_id;
                $student->profile()->updateOrCreate(
                    ['student_id' => $student->id],
                    $profilePayload
                );
            }

            DB::commit();

            return response()->json([
                'message' => 'Student updated successfully',
                'data' => $student->fresh()->load(['user', 'profile.academicYear', 'profile.class'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            report($e);
            return response()->json([
                'message' => 'Failed to update student'
            ], 500);
        }
    }

    /**
     * Remove the specified student (soft delete)
     */
    public function destroy($id)
    {
        $student = Student::findOrFail($id);

        $student->update(['status' => 'dropped']);
        $student->delete();

        return response()->json([
            'message' => 'Student deleted successfully'
        ]);
    }

    /**
     * Get student's academic history
     */
    public function academicHistory($id)
    {
        $student = Student::findOrFail($id);

        $enrollments = $student->enrollments()
            ->with(['academicYear', 'section.class', 'attendances'])
            ->orderBy('enrollment_date', 'desc')
            ->get();

        return response()->json($enrollments);
    }

    /**
     * Get student's financial summary
     */
    public function financialSummary($id)
    {
        $student = Student::findOrFail($id);

        $enrollments = $student->enrollments()
            ->with(['academicYear'])
            ->get();

        $summary = [
            'total_fees' => 0,
            'total_paid' => 0,
            'pending_dues' => 0,
            'by_year' => []
        ];

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

        foreach ($enrollments as $enrollment) {
            $totals = $ledgerTotals->get($enrollment->id);
            $totalFee = (float) ($totals->debits ?? 0);
            $totalPaid = (float) ($totals->credits ?? 0);
            $pending = $totalFee - $totalPaid;

            $summary['total_fees'] += $totalFee;
            $summary['total_paid'] += $totalPaid;
            $summary['pending_dues'] += $pending;

            $summary['by_year'][] = [
                'academic_year' => $enrollment->academicYear->name,
                'total_fee' => $totalFee,
                'total_paid' => $totalPaid,
                'pending' => $pending,
            ];
        }
        return response()->json($summary);
    }

    /**
     * Stream student's avatar for PDF/image consumers (CORS-safe via API middleware).
     */
    public function avatar($id)
    {
        $student = Student::with(['user', 'profile'])->findOrFail($id);
        $path = $student->avatar_url
            ?? $student->profile?->avatar_url
            ?? $student->user?->avatar;

        if (!$path) {
            return response()->json(['message' => 'Avatar not found'], 404);
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return redirect()->away($path);
        }

        $normalized = ltrim($path, '/');
        if (!Storage::disk('public')->exists($normalized)) {
            return response()->json(['message' => 'Avatar file not found'], 404);
        }

        $absolutePath = Storage::disk('public')->path($normalized);
        return response()->file($absolutePath);
    }

    private function createOrAttachParent(
        Student $student,
        string $relation,
        ?string $name,
        ?string $email,
        ?string $mobile,
        ?string $occupation,
        bool $isPrimary
    ): void {
        if (!$name && !$email && !$mobile) {
            return;
        }

        // Email is required in users table. Generate deterministic placeholder when not provided.
        $safeRelation = $relation === 'father' ? 'father' : 'mother';
        $parentEmail = $email ?: sprintf(
            '%s.%d@placeholder.local',
            $safeRelation,
            $student->id
        );

        $parentUser = User::where('email', $parentEmail)->first();
        if (!$parentUser) {
            $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
            $firstName = $parts[0] ?? ucfirst($safeRelation);
            $lastName = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : 'Guardian';

            $parentUser = User::create([
                'email' => $parentEmail,
                'password' => Hash::make('default@123'),
                'role' => 'parent',
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $mobile,
                'status' => 'active',
            ]);
            $parentUser->assignRole('parent');
        }

        $parent = ParentModel::firstOrCreate(
            ['user_id' => $parentUser->id],
            [
                'occupation' => $occupation,
                'address' => $student->address,
                'emergency_contact' => $mobile,
            ]
        );

        $student->parents()->syncWithoutDetaching([
            $parent->id => [
                'relation' => $relation,
                'is_primary' => $isPrimary,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    private function normalizeMedicalInfo(mixed $value): mixed
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }

        return $value;
    }
}
