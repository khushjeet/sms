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

class TeacherController extends Controller
{
    public function index(Request $request)
    {
        $query = Staff::query()->with(['user', 'documents']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
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

        $teachers = $query->paginate((int) $request->input('per_page', 15));

        return response()->json($teachers);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);
        $panNumber = $validated['pan_number'] ?? $validated['pan_card'] ?? null;

        return DB::transaction(function () use ($request, $validated, $panNumber) {
            $user = User::create([
                'email' => $validated['email'],
                'password' => Hash::make($validated['password'] ?? 'default@123'),
                'role' => 'teacher',
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'phone' => $validated['phone'] ?? null,
                'status' => 'active',
            ]);
            $user->assignRole('teacher', $request->user()?->id);

            if ($request->hasFile('image')) {
                $user->avatar = $request->file('image')->store('teachers/avatars', 'public');
                $user->save();
            }

            $staff = Staff::create([
                'user_id' => $user->id,
                'employee_id' => $validated['employee_id'],
                'joining_date' => $validated['joining_date'],
                'employee_type' => 'teaching',
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
                'message' => 'Teacher profile created successfully',
                'data' => $staff->load(['user', 'documents']),
            ], 201);
        });
    }

    public function show(int $id)
    {
        $staff = Staff::with(['user', 'documents'])->findOrFail($id);
        return response()->json($staff);
    }

    public function update(Request $request, int $id)
    {
        $staff = Staff::with('user')->findOrFail($id);
        $validated = $this->validatePayload($request, $staff);
        $panNumber = $validated['pan_number'] ?? $validated['pan_card'] ?? null;

        return DB::transaction(function () use ($request, $staff, $validated, $panNumber) {
            $userPayload = [];
            foreach (['first_name', 'last_name', 'phone'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $userPayload[$field] = $validated[$field];
                }
            }

            if (array_key_exists('email', $validated)) {
                $userPayload['email'] = $validated['email'];
            }

            if (!empty($userPayload)) {
                $staff->user->update($userPayload);
            }

            if ($request->hasFile('image')) {
                if ($staff->user->avatar) {
                    Storage::disk('public')->delete($staff->user->avatar);
                }
                $staff->user->avatar = $request->file('image')->store('teachers/avatars', 'public');
                $staff->user->save();
            }

            $staffPayload = [];
            foreach ([
                'employee_id',
                'joining_date',
                'designation',
                'department',
                'qualification',
                'salary',
                'date_of_birth',
                'gender',
                'address',
                'emergency_contact',
                'aadhar_number',
                'status',
                'resignation_date',
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
                'message' => 'Teacher profile updated successfully',
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

        return response()->json([
            'message' => 'Teacher profile archived successfully',
        ]);
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

    private function validatePayload(Request $request, ?Staff $staff = null): array
    {
        $staffId = $staff?->id;
        $userId = $staff?->user_id;

        $commonRules = [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => [$staff ? 'nullable' : 'required', 'string', 'min:8'],
            'employee_id' => ['required', 'string', 'max:50', Rule::unique('staff', 'employee_id')->ignore($staffId)],
            'joining_date' => ['required', 'date'],
            'designation' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'qualification' => ['nullable', 'string', 'max:255'],
            'salary' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'date_of_birth' => ['required', 'date'],
            'gender' => ['required', 'in:male,female,other'],
            'address' => ['nullable', 'string'],
            'emergency_contact' => ['nullable', 'string', 'max:50'],
            'aadhar_number' => ['nullable', 'string', 'max:20', Rule::unique('staff', 'aadhar_number')->ignore($staffId)],
            'pan_number' => ['nullable', 'string', 'max:20', Rule::unique('staff', 'pan_number')->ignore($staffId)],
            'pan_card' => ['nullable', 'string', 'max:20', Rule::unique('staff', 'pan_number')->ignore($staffId)],
            'status' => ['nullable', 'in:active,on_leave,resigned,terminated'],
            'resignation_date' => ['nullable', 'date'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'documents' => ['nullable', 'array'],
            'documents.*' => ['file', 'mimes:pdf,doc,docx,jpg,jpeg,png,webp', 'max:10240'],
            'document_types' => ['nullable', 'array'],
            'document_types.*' => ['nullable', 'in:resume,identity,certificate,pan_card,other'],
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
            $path = $file->storeAs('teachers/documents/' . now()->format('Y/m'), $storedName, 'public');
            $documentType = $types[$index] ?? null;
            if (!in_array($documentType, ['resume', 'identity', 'certificate', 'pan_card', 'other'], true)) {
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
