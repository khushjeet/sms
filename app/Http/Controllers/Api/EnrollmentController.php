<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\AcademicYear;
use App\Models\FeeAssignment;
use App\Services\Email\EventNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnrollmentController extends Controller
{
    /**
     * Get all enrollments with filters
     */
    public function index(Request $request)
    {
        $query = Enrollment::with([
            'student.user',
            'student.profile.class',
            'academicYear',
            'classModel',
            'section.class',
            'promotedFromEnrollment.academicYear', // Include promotion history
        ]);

        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';

            $query->where(function ($q) use ($search, $like) {
                if (is_numeric($search)) {
                    $q->orWhere('id', (int) $search)
                        ->orWhere('roll_number', (int) $search)
                        ->orWhere('student_id', (int) $search);
                }

                $q->orWhereHas('student', function ($sq) use ($like) {
                    $sq->where('admission_number', 'like', $like)
                        ->orWhereHas('user', function ($uq) use ($like) {
                            $uq->where('first_name', 'like', $like)
                                ->orWhere('last_name', 'like', $like)
                                ->orWhere('email', 'like', $like);
                        });
                });
            });
        }

        if ($request->filled('academic_year_id')) {
            $query->where('academic_year_id', (int) $request->academic_year_id);
        }

        if ($request->filled('section_id')) {
            $query->where('section_id', (int) $request->section_id);
        }

        if ($request->filled('class_id')) {
            $query->where('class_id', (int) $request->class_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by student to see their enrollment history
        if ($request->filled('student_id')) {
            $query->where('student_id', (int) $request->student_id);
        }

        $perPage = (int) $request->input('per_page', 15);
        $perPage = max(1, min($perPage, 200));

        $enrollments = $query->paginate($perPage);

        return response()->json($enrollments);
    }

    /**
     * Create a new enrollment
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'class_id' => 'nullable|required_without:section_id|exists:classes,id',
            'section_id' => 'nullable|exists:sections,id', // SRS: Section may be null
            'roll_number' => 'nullable|integer|min:1',
            'enrollment_date' => 'required|date',
            'remarks' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            if (!$this->isAcademicYearActive((int) $validated['academic_year_id'])) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Cannot create enrollment in an inactive academic year'
                ], 422);
            }

            if (!empty($validated['section_id'])) {
                $sectionCheckError = $this->validateSectionForAcademicYear(
                    (int) $validated['section_id'],
                    (int) $validated['academic_year_id']
                );

                if ($sectionCheckError !== null) {
                    DB::rollBack();
                    return response()->json(['message' => $sectionCheckError], 422);
                }

                if (!empty($validated['class_id'])) {
                    $targetSection = Section::find((int) $validated['section_id']);
                    if ($targetSection && (int) $targetSection->class_id !== (int) $validated['class_id']) {
                        DB::rollBack();
                        return response()->json([
                            'message' => 'Selected section does not belong to the selected class'
                        ], 422);
                    }
                }
            }

            $section = !empty($validated['section_id']) ? Section::find((int) $validated['section_id']) : null;
            $targetClassId = $section?->class_id ?? ($validated['class_id'] ?? null);

            // Check if enrollment already exists for this student and academic year
            $existingEnrollment = Enrollment::where('student_id', $validated['student_id'])
                ->where('academic_year_id', $validated['academic_year_id'])
                ->lockForUpdate()
                ->first();

            if ($existingEnrollment) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Student already enrolled for this academic year'
                ], 422);
            }

            // Create enrollment
            $enrollment = Enrollment::create([
                'student_id' => $validated['student_id'],
                'academic_year_id' => $validated['academic_year_id'],
                'class_id' => $targetClassId,
                'section_id' => $validated['section_id'] ?? null, // SRS: Section may be null
                'roll_number' => $validated['roll_number'],
                'enrollment_date' => $validated['enrollment_date'],
                'status' => 'active',
                'is_locked' => false,
                'remarks' => $validated['remarks'] ?? null,
            ]);

            if ($targetClassId) {
                $enrollment->student->profile()->updateOrCreate(
                    ['student_id' => $enrollment->student_id],
                    [
                        'academic_year_id' => $validated['academic_year_id'],
                        'class_id' => (int) $targetClassId,
                        'roll_number' => $validated['roll_number'] ?? null,
                    ]
                );
            }

            // Auto-create fee assignment based on class fee structure (if section exists)
            if ($enrollment->section_id) {
                $this->createFeeAssignment($enrollment);
            }

            DB::commit();

            app(EventNotificationService::class)->notifyEnrollmentEvent(
                $enrollment->fresh(['student.user', 'student.profile', 'student.parents.user', 'academicYear', 'classModel', 'section.class']),
                'Enrollment created successfully.'
            );

            return response()->json([
                'message' => 'Enrollment created successfully',
                'data' => $enrollment->load(['student.user', 'academicYear', 'classModel', 'section.class', 'feeAssignment'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create enrollment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show specific enrollment details
     */
    public function show($id)
    {
        $enrollment = Enrollment::with([
            'student.user',
            'student.profile.class',
            'student.parents.user',
            'academicYear',
            'classModel',
            'section.class',
            'promotedFromEnrollment.academicYear', // Previous enrollment (if promoted/repeated)
            'promotedFromEnrollment.classModel',
            'promotedFromEnrollment.section.class', // Previous class/section info
            'promotedToEnrollment.academicYear', // Next enrollment (if exists)
            'promotedToEnrollment.classModel',
            'promotedToEnrollment.section.class', // Next class/section info
            'feeAssignment',
            'payments',
            'attendances',
        ])->findOrFail($id);

        return response()->json($enrollment);
    }

    /**
     * Update enrollment
     */
    public function update(Request $request, $id)
    {
        $enrollment = Enrollment::findOrFail($id);

        if ($enrollment->is_locked) {
            return response()->json([
                'message' => 'Cannot update locked enrollment'
            ], 422);
        }

        $validated = $request->validate([
            'class_id' => 'sometimes|nullable|exists:classes,id',
            'section_id' => 'sometimes|nullable|exists:sections,id',
            'roll_number' => 'nullable|integer|min:1',
            'remarks' => 'nullable|string',
        ]);

        if (array_key_exists('section_id', $validated) && !is_null($validated['section_id'])) {
            $sectionCheckError = $this->validateSectionForAcademicYear(
                (int) $validated['section_id'],
                (int) $enrollment->academic_year_id
            );

            if ($sectionCheckError !== null) {
                return response()->json(['message' => $sectionCheckError], 422);
            }

            if (array_key_exists('class_id', $validated) && !is_null($validated['class_id'])) {
                $targetSection = Section::find((int) $validated['section_id']);
                if ($targetSection && (int) $targetSection->class_id !== (int) $validated['class_id']) {
                    return response()->json([
                        'message' => 'Selected section does not belong to the selected class'
                    ], 422);
                }
            }
        }

        if (array_key_exists('section_id', $validated) && is_null($validated['section_id']) && empty($validated['class_id'])) {
            return response()->json([
                'message' => 'class_id is required when section is unassigned'
            ], 422);
        }

        if (array_key_exists('section_id', $validated) && !is_null($validated['section_id'])) {
            $targetSection = Section::find((int) $validated['section_id']);
            if ($targetSection) {
                $validated['class_id'] = $targetSection->class_id;
            }
        }

        $enrollment->update($validated);

        app(EventNotificationService::class)->notifyEnrollmentEvent(
            $enrollment->fresh(['student.user', 'student.profile', 'student.parents.user', 'academicYear', 'classModel', 'section.class']),
            'Enrollment updated successfully.'
        );

        return response()->json([
            'message' => 'Enrollment updated successfully',
            'data' => $enrollment->fresh()
        ]);
    }

    /**
     * Promote student to next class
     */
    public function promote(Request $request, $id)
    {
        $currentEnrollment = Enrollment::with('section.class')->findOrFail($id);

        if ($currentEnrollment->status !== 'active' || $currentEnrollment->is_locked) {
            return response()->json([
                'message' => 'Only active enrollments can be promoted'
            ], 422);
        }

        $validated = $request->validate([
            'new_section_id' => 'nullable|exists:sections,id', // SRS: Section may be null
            'new_class_id' => 'nullable|required_without:new_section_id|exists:classes,id',
            'new_academic_year_id' => 'required|exists:academic_years,id',
            'roll_number' => 'nullable|integer|min:1',
            'remarks' => 'nullable|string',
        ]);

        if ((int) $validated['new_academic_year_id'] === (int) $currentEnrollment->academic_year_id) {
            return response()->json([
                'message' => 'New academic year must be different from the current enrollment'
            ], 422);
        }

        try {
            DB::beginTransaction();

            if (!$this->isAcademicYearActive((int) $validated['new_academic_year_id'])) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Cannot promote to an inactive academic year'
                ], 422);
            }

            if (!empty($validated['new_section_id'])) {
                $sectionCheckError = $this->validateSectionForAcademicYear(
                    (int) $validated['new_section_id'],
                    (int) $validated['new_academic_year_id']
                );
                if ($sectionCheckError !== null) {
                    DB::rollBack();
                    return response()->json(['message' => $sectionCheckError], 422);
                }

                if (!empty($validated['new_class_id'])) {
                    $targetSection = Section::find((int) $validated['new_section_id']);
                    if ($targetSection && (int) $targetSection->class_id !== (int) $validated['new_class_id']) {
                        DB::rollBack();
                        return response()->json([
                            'message' => 'Selected section does not belong to the selected class'
                        ], 422);
                    }
                }
            }

            $currentEnrollment = Enrollment::where('id', $id)->lockForUpdate()->firstOrFail();

            $existingTargetEnrollment = Enrollment::where('student_id', $currentEnrollment->student_id)
                ->where('academic_year_id', (int) $validated['new_academic_year_id'])
                ->lockForUpdate()
                ->first();

            if ($existingTargetEnrollment) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Student already has an enrollment in the target academic year'
                ], 422);
            }

            // Close current enrollment
            $currentEnrollment->update([
                'status' => 'promoted',
                'is_locked' => true,
            ]);

            // Build remarks
            $remarks = $validated['remarks'] ?? 'Promoted';
            if (empty($validated['remarks']) && $currentEnrollment->section && $currentEnrollment->section->class) {
                $remarks = 'Promoted from ' . $currentEnrollment->section->class->name;
            }

            $newSection = null;
            if (!empty($validated['new_section_id'])) {
                $newSection = Section::find((int) $validated['new_section_id']);
            }

            $targetClassId = $newSection?->class_id ?? ($validated['new_class_id'] ?? null);

            // Create new enrollment
            $newEnrollment = Enrollment::create([
                'student_id' => $currentEnrollment->student_id,
                'academic_year_id' => $validated['new_academic_year_id'],
                'class_id' => $targetClassId,
                'section_id' => $validated['new_section_id'] ?? null, // SRS: Section may be null
                'roll_number' => $validated['roll_number'] ?? null,
                'enrollment_date' => now(),
                'status' => 'active',
                'is_locked' => false,
                'promoted_from_enrollment_id' => $currentEnrollment->id,
                'remarks' => $remarks,
            ]);

            if ($targetClassId) {
                $newEnrollment->student->profile()->updateOrCreate(
                    ['student_id' => $newEnrollment->student_id],
                    [
                        'academic_year_id' => $validated['new_academic_year_id'],
                        'class_id' => (int) $targetClassId,
                        'roll_number' => $validated['roll_number'] ?? null,
                    ]
                );
            }

            // Create fee assignment for new enrollment (if section exists)
            if ($newEnrollment->section_id) {
                $this->createFeeAssignment($newEnrollment);
            }

            DB::commit();

            app(EventNotificationService::class)->notifyEnrollmentEvent(
                $newEnrollment->fresh(['student.user', 'student.profile', 'student.parents.user', 'academicYear', 'classModel', 'section.class']),
                'Student promoted successfully.'
            );

            return response()->json([
                'message' => 'Student promoted successfully',
                'data' => $newEnrollment->load([
                    'student.user',
                    'academicYear',
                    'classModel',
                    'section.class',
                    'promotedFromEnrollment.classModel',
                    'promotedFromEnrollment.academicYear',
                    'promotedFromEnrollment.section.class'
                ])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to promote student',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark student as repeated (same class, new academic year)
     */
    public function repeat(Request $request, $id)
    {
        $currentEnrollment = Enrollment::findOrFail($id);

        if ($currentEnrollment->status !== 'active' || $currentEnrollment->is_locked) {
            return response()->json([
                'message' => 'Only active enrollments can be repeated'
            ], 422);
        }

        $validated = $request->validate([
            'new_academic_year_id' => 'required|exists:academic_years,id',
            'class_id' => 'nullable|exists:classes,id',
            'section_id' => 'nullable|exists:sections,id', // SRS: Section may be null
            'remarks' => 'nullable|string',
        ]);

        if ((int) $validated['new_academic_year_id'] === (int) $currentEnrollment->academic_year_id) {
            return response()->json([
                'message' => 'New academic year must be different from the current enrollment'
            ], 422);
        }

        try {
            DB::beginTransaction();

            if (!$this->isAcademicYearActive((int) $validated['new_academic_year_id'])) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Cannot repeat into an inactive academic year'
                ], 422);
            }

            if (!empty($validated['section_id'])) {
                $sectionCheckError = $this->validateSectionForAcademicYear(
                    (int) $validated['section_id'],
                    (int) $validated['new_academic_year_id']
                );
                if ($sectionCheckError !== null) {
                    DB::rollBack();
                    return response()->json(['message' => $sectionCheckError], 422);
                }

                if (!empty($validated['class_id'])) {
                    $targetSection = Section::find((int) $validated['section_id']);
                    if ($targetSection && (int) $targetSection->class_id !== (int) $validated['class_id']) {
                        DB::rollBack();
                        return response()->json([
                            'message' => 'Selected section does not belong to the selected class'
                        ], 422);
                    }
                }
            }

            $currentEnrollment = Enrollment::where('id', $id)->lockForUpdate()->firstOrFail();

            $existingTargetEnrollment = Enrollment::where('student_id', $currentEnrollment->student_id)
                ->where('academic_year_id', (int) $validated['new_academic_year_id'])
                ->lockForUpdate()
                ->first();

            if ($existingTargetEnrollment) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Student already has an enrollment in the target academic year'
                ], 422);
            }

            // Close current enrollment
            $currentEnrollment->update([
                'status' => 'repeated',
                'is_locked' => true,
            ]);

            $section = !empty($validated['section_id']) ? Section::find((int) $validated['section_id']) : null;
            $targetClassId = $section?->class_id ?? ($validated['class_id'] ?? $currentEnrollment->class_id);

            if (empty($targetClassId)) {
                DB::rollBack();
                return response()->json([
                    'message' => 'class_id is required when section is unassigned'
                ], 422);
            }

            // Create new enrollment in same class
            $newEnrollment = Enrollment::create([
                'student_id' => $currentEnrollment->student_id,
                'academic_year_id' => $validated['new_academic_year_id'],
                'class_id' => $targetClassId,
                'section_id' => $validated['section_id'] ?? null, // SRS: Section may be null
                'enrollment_date' => now(),
                'status' => 'active',
                'is_locked' => false,
                'promoted_from_enrollment_id' => $currentEnrollment->id, // Track that this came from previous enrollment
                'remarks' => $validated['remarks'] ?? 'Repeated class',
            ]);

            $newEnrollment->student->profile()->updateOrCreate(
                ['student_id' => $newEnrollment->student_id],
                [
                    'academic_year_id' => $validated['new_academic_year_id'],
                    'class_id' => (int) $targetClassId,
                ]
            );

            // Create fee assignment (if section exists)
            if ($newEnrollment->section_id) {
                $this->createFeeAssignment($newEnrollment);
            }

            DB::commit();

            app(EventNotificationService::class)->notifyEnrollmentEvent(
                $newEnrollment->fresh(['student.user', 'student.profile', 'student.parents.user', 'academicYear', 'classModel', 'section.class']),
                'Student repeat enrollment created successfully.'
            );

            return response()->json([
                'message' => 'Student marked as repeated successfully',
                'data' => $newEnrollment->load([
                    'student.user',
                    'academicYear',
                    'classModel',
                    'section.class',
                    'promotedFromEnrollment.classModel',
                    'promotedFromEnrollment.academicYear',
                    'promotedFromEnrollment.section.class'
                ])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to repeat student',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get academic history chain for an enrollment
     * Returns all enrollments linked through promotions/repeats
     * SRS: Track student progression through academic years
     */
    public function academicHistory($id)
    {
        $enrollment = Enrollment::findOrFail($id);

        $chain = $enrollment->getAcademicHistoryChain();

        // Load relationships for all enrollments in the chain
        $chain = collect($chain)->map(function ($enrollment) {
            return $enrollment->load([
                'academicYear',
                'classModel',
                'section.class',
                'student.user'
            ]);
        });

        return response()->json([
            'current_enrollment_id' => $enrollment->id,
            'history' => $chain->map(function ($enrollment) {
                return [
                    'id' => $enrollment->id,
                    'academic_year' => $enrollment->academicYear->name ?? null,
                    'academic_year_id' => $enrollment->academic_year_id,
                    'class' => $enrollment->classModel?->name ?? $enrollment->section?->class?->name ?? 'No Class',
                    'section' => $enrollment->section?->name ?? 'No Section',
                    'roll_number' => $enrollment->roll_number,
                    'status' => $enrollment->status,
                    'enrollment_date' => $enrollment->enrollment_date,
                    'is_locked' => $enrollment->is_locked,
                    'remarks' => $enrollment->remarks,
                    'promoted_from_enrollment_id' => $enrollment->promoted_from_enrollment_id,
                ];
            }),
            'total_enrollments' => count($chain)
        ]);
    }

    /**
     * Transfer student / Issue TC
     */
    public function transfer(Request $request, $id)
    {
        $enrollment = Enrollment::findOrFail($id);

        if ($enrollment->status !== 'active' || $enrollment->is_locked) {
            return response()->json([
                'message' => 'Only active enrollments can be transferred'
            ], 422);
        }

        $validated = $request->validate([
            'transfer_date' => 'required|date',
            'remarks' => 'required|string',
        ]);

        try {
            DB::beginTransaction();

            // Update enrollment status
            $enrollment->update([
                'status' => 'transferred',
                'is_locked' => true,
                'remarks' => $validated['remarks'] . ' (Transfer date: ' . $validated['transfer_date'] . ')',
            ]);

            // Update student status
            $enrollment->student->update([
                'status' => 'transferred',
            ]);

            DB::commit();

            app(EventNotificationService::class)->notifyEnrollmentEvent(
                $enrollment->fresh(['student.user', 'student.profile', 'student.parents.user', 'academicYear', 'classModel', 'section.class']),
                'Student transferred successfully.'
            );

            return response()->json([
                'message' => 'Student transferred successfully',
                'data' => $enrollment->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to transfer student',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper: Create fee assignment for enrollment
     * SRS: Section may be null - handle fee assignment accordingly
     */
    private function createFeeAssignment(Enrollment $enrollment)
    {
        // If no section, we can't determine class, so skip fee assignment
        if (!$enrollment->section_id) {
            return;
        }

        $section = Section::with('class')->find($enrollment->section_id);

        if (!$section || !$section->class) {
            return;
        }

        // Get base fees for the class
        $feeStructures = $section->class->feeStructures()
            ->where('academic_year_id', $enrollment->academic_year_id)
            ->get();

        $baseFee = $feeStructures->sum('amount');

        FeeAssignment::firstOrCreate(
            ['enrollment_id' => $enrollment->id],
            [
                'base_fee' => $baseFee,
                'optional_services_fee' => 0,
                'discount' => 0,
                'total_fee' => $baseFee,
            ]
        );
    }

    private function validateSectionForAcademicYear(int $sectionId, int $academicYearId): ?string
    {
        $section = Section::find($sectionId);
        if (!$section) {
            return 'Selected section does not exist';
        }

        if ((int) $section->academic_year_id !== $academicYearId) {
            return 'Selected section does not belong to the selected academic year';
        }

        return null;
    }

    private function isAcademicYearActive(int $academicYearId): bool
    {
        return AcademicYear::where('id', $academicYearId)
            ->where('status', 'active')
            ->exists();
    }
}
