<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\CompiledMark;
use App\Models\CompiledMarkHistory;
use App\Models\AcademicYearExamConfig;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\SchoolSetting;
use App\Models\TeacherMark;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminMarksController extends Controller
{
    public function filters(Request $request)
    {
        $this->requireSuperAdmin($request);

        $validated = $request->validate([
            'class_id' => ['required', 'integer', 'exists:classes,id'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
        ]);

        $context = $this->resolveFilterContext(
            (int) $validated['class_id'],
            (int) $validated['academic_year_id'],
            isset($validated['section_id']) ? (int) $validated['section_id'] : null
        );

        return response()->json($context);
    }

    public function sheet(Request $request)
    {
        $this->requireSuperAdmin($request);

        $validated = $request->validate([
            'class_id' => ['nullable', 'integer', 'exists:classes,id'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'subject_code' => ['nullable', 'string', 'max:100'],
            'marked_on' => ['nullable', 'date'],
            'exam_configuration_id' => ['required', 'integer', 'exists:academic_year_exam_configs,id'],
        ]);

        $scope = $this->resolveScope(
            isset($validated['class_id']) ? (int) $validated['class_id'] : null,
            isset($validated['academic_year_id']) ? (int) $validated['academic_year_id'] : null,
            isset($validated['section_id']) ? (int) $validated['section_id'] : null,
            isset($validated['subject_id']) ? (int) $validated['subject_id'] : null,
            isset($validated['subject_code']) ? (string) $validated['subject_code'] : null
        );
        $examConfig = $this->resolveExamConfiguration(
            (int) $scope['academic_year_id'],
            (int) $validated['exam_configuration_id']
        );
        $markedOn = $validated['marked_on'] ?? now()->toDateString();
        $this->validateMarkedOnWithinAcademicYear($scope, (string) $markedOn);

        $teachers = DB::table('teacher_subject_assignments as tsa')
            ->join('users as u', 'u.id', '=', 'tsa.teacher_id')
            ->where('tsa.class_id', $scope['class_id'])
            ->where('tsa.subject_id', $scope['subject_id'])
            ->where('tsa.academic_year_id', $scope['academic_year_id'])
            ->when($scope['section_id'] !== null, function ($query) use ($scope) {
                $query->where(function ($teacherQuery) use ($scope) {
                    $teacherQuery->whereNull('tsa.section_id')
                        ->orWhere('tsa.section_id', $scope['section_id']);
                });
            })
            ->select('u.id', 'u.first_name', 'u.last_name')
            ->distinct()
            ->orderBy('u.first_name')
            ->orderBy('u.last_name')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')),
            ])
            ->values();

        $rows = $this->scopeEnrollmentRows($scope);
        $enrollmentIds = $rows->pluck('enrollment_id')->all();
        $sectionIds = $rows->pluck('section_id')->unique()->values()->all();

        $teacherMarks = TeacherMark::query()
            ->where('subject_id', $scope['subject_id'])
            ->where('academic_year_id', $scope['academic_year_id'])
            ->where('exam_configuration_id', (int) $examConfig->id)
            ->whereDate('marked_on', $markedOn)
            ->whereIn('enrollment_id', $enrollmentIds)
            ->when(!empty($sectionIds), fn ($query) => $query->whereIn('section_id', $sectionIds))
            ->get();

        $compiledMarks = CompiledMark::query()
            ->where('subject_id', $scope['subject_id'])
            ->where('academic_year_id', $scope['academic_year_id'])
            ->where('exam_configuration_id', (int) $examConfig->id)
            ->whereDate('marked_on', $markedOn)
            ->whereIn('enrollment_id', $enrollmentIds)
            ->when(!empty($sectionIds), fn ($query) => $query->whereIn('section_id', $sectionIds))
            ->get()
            ->keyBy('enrollment_id');

        $teacherMarksByEnrollment = $teacherMarks
            ->groupBy('enrollment_id')
            ->map(fn (Collection $group) => $group->keyBy('teacher_id'));

        $sheetRows = $rows->map(function (array $row) use ($teachers, $teacherMarksByEnrollment, $compiledMarks, $scope) {
            $teacherMarksForRow = $teacherMarksByEnrollment->get($row['enrollment_id'], collect());
            $teacherMarksMatrix = [];

            foreach ($teachers as $teacher) {
                $mark = $teacherMarksForRow->get($teacher['id']);
                $teacherMarksMatrix[(string) $teacher['id']] = [
                    'marks_obtained' => $mark?->marks_obtained !== null ? (float) $mark->marks_obtained : null,
                    'max_marks' => $mark?->max_marks !== null ? (float) $mark->max_marks : null,
                    'remarks' => $mark?->remarks,
                ];
            }

            /** @var \App\Models\CompiledMark|null $compiled */
            $compiled = $compiledMarks->get($row['enrollment_id']);
            $defaultMax = $scope['mapped_max_marks'] ?? 100.0;

            return [
                ...$row,
                'teacher_marks' => $teacherMarksMatrix,
                'compiled_marks_obtained' => $compiled?->marks_obtained !== null ? (float) $compiled->marks_obtained : null,
                'compiled_max_marks' => $compiled?->max_marks !== null ? (float) $compiled->max_marks : $defaultMax,
                'compiled_remarks' => $compiled?->remarks,
                'is_finalized' => (bool) ($compiled?->is_finalized ?? false),
            ];
        })->values();

        $isFinalized = $compiledMarks->isNotEmpty() && $compiledMarks->every(fn (CompiledMark $row) => $row->is_finalized);

        return response()->json([
            'marked_on' => $markedOn,
            'scope' => [
                'class_id' => $scope['class_id'],
                'class_name' => $scope['class_name'],
                'section_id' => $scope['section_id'],
                'section_name' => $scope['section_name'],
                'subject_id' => $scope['subject_id'],
                'subject_name' => $scope['subject_name'],
                'subject_code' => $scope['subject_code'],
                'academic_year_id' => $scope['academic_year_id'],
                'academic_year_name' => $scope['academic_year_name'],
                'exam_configuration_id' => (int) $examConfig->id,
                'exam_configuration_name' => $examConfig->name,
                'mapped_max_marks' => $scope['mapped_max_marks'],
            ],
            'teachers' => $teachers,
            'rows' => $sheetRows,
            'is_finalized' => $isFinalized,
            'empty_state_message' => $sheetRows->isEmpty()
                ? ($scope['section_id'] !== null
                    ? 'No active students are enrolled in the selected section for this academic year.'
                    : 'No active students are enrolled in the selected class for this academic year.')
                : null,
        ]);
    }

    public function compile(Request $request)
    {
        $userId = $this->requireSuperAdmin($request);

        $validated = $request->validate([
            'class_id' => ['nullable', 'integer', 'exists:classes,id'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'subject_code' => ['nullable', 'string', 'max:100'],
            'marked_on' => ['required', 'date'],
            'exam_configuration_id' => ['required', 'integer', 'exists:academic_year_exam_configs,id'],
            'rows' => ['required', 'array', 'min:1', 'max:300'],
            'rows.*.enrollment_id' => ['required', 'integer', 'exists:enrollments,id'],
            'rows.*.marks_obtained' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'rows.*.max_marks' => ['nullable', 'numeric', 'min:1', 'max:1000'],
            'rows.*.remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $scope = $this->resolveScope(
            isset($validated['class_id']) ? (int) $validated['class_id'] : null,
            isset($validated['academic_year_id']) ? (int) $validated['academic_year_id'] : null,
            isset($validated['section_id']) ? (int) $validated['section_id'] : null,
            isset($validated['subject_id']) ? (int) $validated['subject_id'] : null,
            isset($validated['subject_code']) ? (string) $validated['subject_code'] : null
        );
        $examConfig = $this->resolveExamConfiguration(
            (int) $scope['academic_year_id'],
            (int) $validated['exam_configuration_id']
        );
        $markedOn = (string) $validated['marked_on'];
        $this->validateMarkedOnWithinAcademicYear($scope, $markedOn);

        $submittedEnrollmentIds = collect($validated['rows'])
            ->pluck('enrollment_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $allowedEnrollmentIds = Enrollment::query()
            ->when($scope['section_id'] !== null, function ($query) use ($scope) {
                $query->where('section_id', $scope['section_id']);
            }, function ($query) use ($scope) {
                $query->where('class_id', $scope['class_id'])
                    ->where('academic_year_id', $scope['academic_year_id']);
            })
            ->whereIn('id', $submittedEnrollmentIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $invalidEnrollmentIds = array_values(array_diff($submittedEnrollmentIds, $allowedEnrollmentIds));
        if (!empty($invalidEnrollmentIds)) {
            return response()->json([
                'message' => $scope['section_id'] !== null
                    ? 'Some enrollments do not belong to this section.'
                    : 'Some enrollments do not belong to this class and academic year.',
                'invalid_enrollment_ids' => $invalidEnrollmentIds,
            ], 422);
        }

        $finalizedIds = CompiledMark::query()
            ->where('subject_id', $scope['subject_id'])
            ->where('academic_year_id', $scope['academic_year_id'])
            ->where('exam_configuration_id', (int) $examConfig->id)
            ->whereDate('marked_on', $markedOn)
            ->whereIn('enrollment_id', $submittedEnrollmentIds)
            ->when($scope['section_id'] !== null, fn ($query) => $query->where('section_id', $scope['section_id']))
            ->where('is_finalized', true)
            ->pluck('enrollment_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if (!empty($finalizedIds)) {
            return response()->json([
                'message' => 'Some rows are already finalized and cannot be modified.',
                'finalized_enrollment_ids' => $finalizedIds,
            ], 422);
        }

        $defaultMaxMarks = $scope['mapped_max_marks'] ?? 100.0;
        $now = now();

        DB::transaction(function () use ($validated, $scope, $examConfig, $markedOn, $defaultMaxMarks, $userId, $now) {
            foreach ($validated['rows'] as $row) {
                $maxMarks = array_key_exists('max_marks', $row) && $row['max_marks'] !== null
                    ? (float) $row['max_marks']
                    : $defaultMaxMarks;
                $marksObtained = array_key_exists('marks_obtained', $row) && $row['marks_obtained'] !== null
                    ? (float) $row['marks_obtained']
                    : null;

                if ($marksObtained !== null && $marksObtained > $maxMarks) {
                    throw ValidationException::withMessages([
                        'rows' => ['Compiled marks cannot exceed max marks.'],
                    ]);
                }

                $sectionId = (int) Enrollment::query()->whereKey((int) $row['enrollment_id'])->value('section_id');
                $compiledMark = CompiledMark::query()
                    ->where('enrollment_id', (int) $row['enrollment_id'])
                    ->where('subject_id', $scope['subject_id'])
                    ->where('section_id', $sectionId)
                    ->where('academic_year_id', $scope['academic_year_id'])
                    ->where('exam_configuration_id', (int) $examConfig->id)
                    ->whereDate('marked_on', $markedOn)
                    ->first();

                if (!$compiledMark) {
                    $compiledMark = new CompiledMark([
                        'enrollment_id' => (int) $row['enrollment_id'],
                        'subject_id' => $scope['subject_id'],
                        'section_id' => $sectionId,
                        'academic_year_id' => $scope['academic_year_id'],
                        'exam_configuration_id' => (int) $examConfig->id,
                        'marked_on' => $markedOn,
                    ]);
                }

                $compiledMark->marks_obtained = $marksObtained;
                $compiledMark->max_marks = $maxMarks;
                $compiledMark->remarks = $row['remarks'] ?? null;
                $compiledMark->compiled_by = $userId;
                $compiledMark->compiled_at = $now;
                $compiledMark->is_finalized = false;
                $compiledMark->finalized_by = null;
                $compiledMark->finalized_at = null;
                $compiledMark->save();

                $action = $compiledMark->wasRecentlyCreated ? 'created' : 'updated';
                $this->recordCompiledMarkHistory($compiledMark->fresh(), $action, $userId, $now, [
                    'source' => 'compile',
                ]);
            }
        });

        return response()->json([
            'message' => 'Compiled marks saved successfully.',
        ]);
    }

    public function finalize(Request $request)
    {
        $userId = $this->requireSuperAdmin($request);

        $validated = $request->validate([
            'class_id' => ['nullable', 'integer', 'exists:classes,id'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'subject_code' => ['nullable', 'string', 'max:100'],
            'marked_on' => ['required', 'date'],
            'exam_configuration_id' => ['required', 'integer', 'exists:academic_year_exam_configs,id'],
        ]);

        $scope = $this->resolveScope(
            isset($validated['class_id']) ? (int) $validated['class_id'] : null,
            isset($validated['academic_year_id']) ? (int) $validated['academic_year_id'] : null,
            isset($validated['section_id']) ? (int) $validated['section_id'] : null,
            isset($validated['subject_id']) ? (int) $validated['subject_id'] : null,
            isset($validated['subject_code']) ? (string) $validated['subject_code'] : null
        );
        $examConfig = $this->resolveExamConfiguration(
            (int) $scope['academic_year_id'],
            (int) $validated['exam_configuration_id']
        );
        $markedOn = (string) $validated['marked_on'];
        $this->validateMarkedOnWithinAcademicYear($scope, $markedOn);

        $scopeEnrollmentIds = $this->scopeEnrollmentRows($scope)
            ->pluck('enrollment_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $query = CompiledMark::query()
            ->where('subject_id', $scope['subject_id'])
            ->where('academic_year_id', $scope['academic_year_id'])
            ->where('exam_configuration_id', (int) $examConfig->id)
            ->whereDate('marked_on', $markedOn)
            ->whereIn('enrollment_id', $scopeEnrollmentIds)
            ->when($scope['section_id'] !== null, fn ($query) => $query->where('section_id', $scope['section_id']));

        $totalRows = (clone $query)->count();
        if ($totalRows === 0) {
            return response()->json([
                'message' => 'No compiled marks found for this filter and date.',
            ], 422);
        }

        $sessionName = trim((string) $examConfig->name);
        if ($sessionName === '') {
            return response()->json([
                'message' => 'Selected exam has an invalid name. Update exam configuration.',
            ], 422);
        }

        $examSession = ExamSession::query()->firstOrCreate(
            [
                'academic_year_id' => $scope['academic_year_id'],
                'class_id' => $scope['class_id'],
                'exam_configuration_id' => (int) $examConfig->id,
            ],
            [
                'name' => $sessionName,
                'status' => 'compiling',
                'created_by' => $userId,
            ]
        );

        $this->ensureExamSessionSnapshots($examSession, $scope, $examConfig, true);

        $affected = 0;
        DB::transaction(function () use ($query, $userId, $examSession, &$affected) {
            $rows = (clone $query)->get();
            $finalizedAt = now();

            foreach ($rows as $row) {
                $row->is_finalized = true;
                $row->finalized_by = $userId;
                $row->finalized_at = $finalizedAt;
                $row->exam_session_id = $examSession->id;
                $row->save();
                $affected++;

                $this->recordCompiledMarkHistory($row, 'finalized', $userId, $finalizedAt, [
                    'source' => 'finalize',
                    'exam_session_id' => (int) $examSession->id,
                ]);
            }
        });

        if ($examSession->status === 'draft') {
            $examSession->status = 'compiling';
            $examSession->save();
        }

        return response()->json([
            'message' => $affected > 0 ? 'Marks finalized successfully.' : 'Marks are already finalized.',
            'rows_finalized' => (int) $affected,
            'rows_total' => (int) $totalRows,
            'exam_session_id' => (int) $examSession->id,
            'exam_session_name' => $examSession->name,
            'exam_configuration_id' => (int) $examConfig->id,
        ]);
    }

    private function requireSuperAdmin(Request $request): int
    {
        $user = $request->user();
        if (!$user || !$user->hasRole('super_admin')) {
            abort(403, 'Super admin access required.');
        }

        return (int) $user->id;
    }

    private function resolveScope(?int $classId, ?int $academicYearId, ?int $sectionId, ?int $subjectId, ?string $subjectCode): array
    {
        if (!$sectionId && !$classId) {
            throw ValidationException::withMessages([
                'class_id' => ['Select class or section.'],
            ]);
        }

        $section = null;
        $sectionIds = [];

        if ($sectionId) {
            $section = DB::table('sections as sec')
                ->join('classes as cls', 'cls.id', '=', 'sec.class_id')
                ->join('academic_years as ay', 'ay.id', '=', 'sec.academic_year_id')
                ->where('sec.id', $sectionId)
                ->select(
                    'sec.id as section_id',
                    'sec.name as section_name',
                    'sec.class_id',
                    'sec.academic_year_id',
                    'cls.name as class_name',
                    'ay.name as academic_year_name',
                    'ay.start_date as academic_year_start_date',
                    'ay.end_date as academic_year_end_date'
                )
                ->first();

            if (!$section) {
                abort(404, 'Section not found.');
            }

            if ($academicYearId !== null && (int) $section->academic_year_id !== $academicYearId) {
                throw ValidationException::withMessages([
                    'academic_year_id' => ['Selected section does not belong to the selected academic year.'],
                ]);
            }

            $sectionIds = [(int) $section->section_id];
        } else {
            if (!$academicYearId) {
                throw ValidationException::withMessages([
                    'academic_year_id' => ['Select academic year.'],
                ]);
            }

            $classScope = DB::table('classes as cls')
                ->where('cls.id', $classId)
                ->select('cls.id as class_id', 'cls.name as class_name')
                ->first();

            $yearScope = DB::table('academic_years as ay')
                ->where('ay.id', $academicYearId)
                ->select(
                    'ay.id as academic_year_id',
                    'ay.name as academic_year_name',
                    'ay.start_date as academic_year_start_date',
                    'ay.end_date as academic_year_end_date'
                )
                ->first();

            if (!$classScope || !$yearScope) {
                throw ValidationException::withMessages([
                    'class_id' => ['Invalid class or academic year selection.'],
                ]);
            }

            $section = (object) [
                'section_id' => null,
                'section_name' => 'All Sections',
                'class_id' => (int) $classScope->class_id,
                'academic_year_id' => (int) $yearScope->academic_year_id,
                'class_name' => $classScope->class_name,
                'academic_year_name' => $yearScope->academic_year_name,
                'academic_year_start_date' => $yearScope->academic_year_start_date,
                'academic_year_end_date' => $yearScope->academic_year_end_date,
            ];

            $sectionIds = DB::table('sections')
                ->where('class_id', (int) $classScope->class_id)
                ->where('academic_year_id', (int) $yearScope->academic_year_id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            if (empty($sectionIds)) {
                throw ValidationException::withMessages([
                    'section_id' => ['No sections found for the selected class and academic year.'],
                ]);
            }
        }

        $subjectQuery = DB::table('subjects');
        if ($subjectId) {
            $subjectQuery->where('id', $subjectId);
        } else {
            $cleanCode = strtoupper(trim((string) $subjectCode));
            if ($cleanCode === '') {
                throw ValidationException::withMessages([
                    'subject_id' => ['Select subject or provide subject code.'],
                ]);
            }
            $subjectQuery->where(function ($query) use ($cleanCode) {
                $query->whereRaw('UPPER(subject_code) = ?', [$cleanCode])
                    ->orWhereRaw('UPPER(code) = ?', [$cleanCode]);
            });
        }

        $subject = $subjectQuery->select('id', 'name', 'subject_code', 'code')->first();
        if (!$subject) {
            throw ValidationException::withMessages([
                'subject_id' => ['Subject not found.'],
            ]);
        }

        $classSubjectMapping = DB::table('class_subjects')
            ->where('class_id', (int) $section->class_id)
            ->where('subject_id', (int) $subject->id)
            ->where('academic_year_id', (int) $section->academic_year_id)
            ->select('max_marks')
            ->first();

        if (!$classSubjectMapping) {
            throw ValidationException::withMessages([
                'subject_id' => ['Subject is not assigned to the selected class for this academic year.'],
            ]);
        }

        return [
            'section_id' => $section->section_id !== null ? (int) $section->section_id : null,
            'section_name' => $section->section_name,
            'class_id' => (int) $section->class_id,
            'class_name' => $section->class_name,
            'academic_year_id' => (int) $section->academic_year_id,
            'academic_year_name' => $section->academic_year_name,
            'subject_id' => (int) $subject->id,
            'subject_name' => $subject->name,
            'subject_code' => $subject->subject_code ?: $subject->code,
            'academic_year_start_date' => $section->academic_year_start_date,
            'academic_year_end_date' => $section->academic_year_end_date,
            'mapped_max_marks' => $classSubjectMapping->max_marks !== null ? (float) $classSubjectMapping->max_marks : null,
            'section_ids' => $sectionIds,
        ];
    }

    private function resolveExamConfiguration(int $academicYearId, int $examConfigurationId): AcademicYearExamConfig
    {
        $examConfig = AcademicYearExamConfig::query()->findOrFail($examConfigurationId);

        if ((int) $examConfig->academic_year_id !== $academicYearId) {
            abort(422, 'Selected exam does not belong to this academic year.');
        }

        if (!$examConfig->is_active) {
            abort(422, 'Selected exam is inactive. Activate it from exam configuration first.');
        }

        return $examConfig;
    }

    private function scopeEnrollmentRows(array $scope): Collection
    {
        return Enrollment::query()
            ->with('student.user')
            ->when($scope['section_id'] !== null, function ($query) use ($scope) {
                $query->where('section_id', $scope['section_id']);
            }, function ($query) use ($scope) {
                $query->where('class_id', $scope['class_id'])
                    ->where('academic_year_id', $scope['academic_year_id']);
            })
            ->where('status', 'active')
            ->orderBy('roll_number')
            ->get()
            ->map(fn (Enrollment $enrollment) => [
                'enrollment_id' => (int) $enrollment->id,
                'student_id' => (int) $enrollment->student_id,
                'section_id' => $enrollment->section_id !== null ? (int) $enrollment->section_id : null,
                'roll_number' => $enrollment->roll_number,
                'student_name' => $enrollment->student?->full_name,
            ])
            ->values();
    }

    private function resolveFilterContext(int $classId, int $academicYearId, ?int $sectionId = null): array
    {
        $class = DB::table('classes')
            ->where('id', $classId)
            ->select('id', 'name')
            ->first();

        if (!$class) {
            abort(404, 'Class not found.');
        }

        $academicYear = AcademicYear::query()->findOrFail($academicYearId);

        $sections = DB::table('sections as sec')
            ->leftJoin('academic_years as ay', 'ay.id', '=', 'sec.academic_year_id')
            ->where('sec.class_id', $classId)
            ->where('sec.academic_year_id', $academicYearId)
            ->select(
                'sec.id',
                'sec.name',
                'sec.class_id',
                'sec.academic_year_id',
                'sec.status',
                'ay.name as academic_year_name',
                'ay.start_date as academic_year_start_date',
                'ay.end_date as academic_year_end_date',
                'ay.is_current as academic_year_is_current'
            )
            ->orderByDesc('ay.is_current')
            ->orderByDesc('sec.academic_year_id')
            ->orderBy('sec.name')
            ->get();

        if ($sections->isEmpty()) {
            return [
                'class_id' => (int) $class->id,
                'class_name' => $class->name,
                'academic_year_id' => (int) $academicYear->id,
                'section_id' => null,
                'academic_year' => [
                    'id' => (int) $academicYear->id,
                    'name' => $academicYear->name,
                    'start_date' => Carbon::parse((string) $academicYear->start_date)->toDateString(),
                    'end_date' => Carbon::parse((string) $academicYear->end_date)->toDateString(),
                    'is_current' => (bool) $academicYear->is_current,
                ],
                'sections' => [],
                'subjects' => [],
                'exam_configurations' => [],
                'messages' => [
                    'sections' => 'No sections are available for the selected class.',
                    'academic_year' => 'No section is mapped for the selected class in this academic year.',
                ],
            ];
        }

        $selectedSection = $sectionId
            ? $sections->firstWhere('id', $sectionId)
            : null;

        if ($sectionId && !$selectedSection) {
            throw ValidationException::withMessages([
                'section_id' => ['Selected section does not belong to the selected class.'],
            ]);
        }

        $effectiveSection = $selectedSection ?: $sections->first();

        if (!$effectiveSection) {
            return [
                'class_id' => (int) $class->id,
                'class_name' => $class->name,
                'academic_year_id' => (int) $academicYear->id,
                'section_id' => $selectedSection ? (int) $selectedSection->id : null,
                'academic_year' => [
                    'id' => (int) $academicYear->id,
                    'name' => $academicYear->name,
                    'start_date' => Carbon::parse((string) $academicYear->start_date)->toDateString(),
                    'end_date' => Carbon::parse((string) $academicYear->end_date)->toDateString(),
                    'is_current' => (bool) $academicYear->is_current,
                ],
                'sections' => $sections->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'name' => $row->name,
                    'class_id' => (int) $row->class_id,
                    'academic_year_id' => $row->academic_year_id !== null ? (int) $row->academic_year_id : null,
                    'status' => $row->status,
                ])->values(),
                'subjects' => [],
                'exam_configurations' => [],
                'messages' => [
                    'academic_year' => 'Academic year is not mapped for the selected class/section.',
                ],
            ];
        }

        $sectionsForYear = $sections
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'class_id' => (int) $row->class_id,
                'academic_year_id' => $row->academic_year_id !== null ? (int) $row->academic_year_id : null,
                'status' => $row->status,
                'academic_year_name' => $row->academic_year_name,
            ])
            ->values();

        $subjects = DB::table('class_subjects as cs')
            ->join('subjects as s', 's.id', '=', 'cs.subject_id')
            ->where('cs.class_id', $classId)
            ->where('cs.academic_year_id', $academicYearId)
            ->select(
                's.id',
                's.name',
                's.subject_code',
                's.code',
                'cs.max_marks',
                'cs.pass_marks',
                'cs.academic_year_exam_config_id'
            )
            ->orderBy('s.name')
            ->distinct()
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'subject_code' => $row->subject_code,
                'code' => $row->code,
                'max_marks' => $row->max_marks !== null ? (float) $row->max_marks : null,
                'pass_marks' => $row->pass_marks !== null ? (float) $row->pass_marks : null,
                'academic_year_exam_config_id' => $row->academic_year_exam_config_id !== null ? (int) $row->academic_year_exam_config_id : null,
            ])
            ->values();

        $examConfigurations = AcademicYearExamConfig::query()
            ->where('academic_year_id', $academicYearId)
            ->where('is_active', true)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->map(fn (AcademicYearExamConfig $config) => [
                'id' => (int) $config->id,
                'academic_year_id' => (int) $config->academic_year_id,
                'name' => $config->name,
                'sequence' => (int) $config->sequence,
                'is_active' => (bool) $config->is_active,
            ])
            ->values();

        $messages = [];
        if ($subjects->isEmpty()) {
            $messages['subjects'] = 'Subject not assigned for the selected class in this academic year.';
        }
        if ($examConfigurations->isEmpty()) {
            $messages['exam_configurations'] = 'Exam configuration is not created for the selected academic year.';
        }

        return [
            'class_id' => (int) $class->id,
            'class_name' => $class->name,
            'academic_year_id' => (int) $academicYear->id,
            'section_id' => $selectedSection ? (int) $selectedSection->id : null,
            'academic_year' => [
                'id' => (int) $academicYear->id,
                'name' => $academicYear->name,
                'start_date' => Carbon::parse((string) $academicYear->start_date)->toDateString(),
                'end_date' => Carbon::parse((string) $academicYear->end_date)->toDateString(),
                'is_current' => (bool) $academicYear->is_current,
            ],
            'sections' => $sectionsForYear,
            'subjects' => $subjects,
            'exam_configurations' => $examConfigurations,
            'messages' => $messages,
        ];
    }

    private function validateMarkedOnWithinAcademicYear(array $scope, string $markedOn): void
    {
        $startDate = $scope['academic_year_start_date'] ?? null;
        $endDate = $scope['academic_year_end_date'] ?? null;

        if (!$startDate || !$endDate) {
            throw ValidationException::withMessages([
                'marked_on' => ['Academic year date range is not configured for the selected class/section.'],
            ]);
        }

        $markedOnDate = Carbon::parse($markedOn)->toDateString();
        $start = Carbon::parse((string) $startDate)->toDateString();
        $end = Carbon::parse((string) $endDate)->toDateString();

        if ($markedOnDate < $start || $markedOnDate > $end) {
            throw ValidationException::withMessages([
                'marked_on' => ["Date must be within the academic year ({$start} to {$end})."],
            ]);
        }
    }

    private function ensureExamSessionSnapshots(ExamSession $examSession, array $scope, AcademicYearExamConfig $examConfig, bool $lockIdentity = false): void
    {
        if ($examSession->identity_locked_at !== null) {
            return;
        }

        $examSession->class_name_snapshot = $examSession->class_name_snapshot ?: (string) $scope['class_name'];
        $examSession->exam_name_snapshot = $examSession->exam_name_snapshot ?: trim((string) $examConfig->name);
        $examSession->academic_year_label_snapshot = $examSession->academic_year_label_snapshot ?: (string) $scope['academic_year_name'];
        $examSession->school_snapshot = $examSession->school_snapshot ?: $this->buildSchoolSnapshot();

        if ($lockIdentity) {
            $examSession->identity_locked_at = now();
        }

        $examSession->save();
    }

    private function buildSchoolSnapshot(): array
    {
        return [
            'name' => SchoolSetting::getValue('school_name', config('school.name')),
            'address' => SchoolSetting::getValue('school_address', config('school.address')),
            'phone' => SchoolSetting::getValue('school_phone', config('school.phone')),
            'mobile_number_1' => SchoolSetting::getValue('school_mobile_number_1'),
            'mobile_number_2' => SchoolSetting::getValue('school_mobile_number_2'),
            'website' => SchoolSetting::getValue('school_website', config('school.website')),
            'registration_number' => SchoolSetting::getValue('school_registration_number', config('school.reg_no')),
            'udise_code' => SchoolSetting::getValue('school_udise_code', config('school.udise')),
            'logo_url' => SchoolSetting::getValue('school_logo_url', config('school.logo_url')),
            'watermark_logo_url' => SchoolSetting::getValue('school_watermark_logo_url'),
            'watermark_text' => SchoolSetting::getValue('school_watermark_text', SchoolSetting::getValue('school_name', config('school.name'))),
        ];
    }

    private function recordCompiledMarkHistory(CompiledMark $compiledMark, string $action, int $userId, $changedAt, array $metadata = []): void
    {
        $nextVersion = ((int) CompiledMarkHistory::query()
            ->where('compiled_mark_id', $compiledMark->id)
            ->max('version_no')) + 1;

        CompiledMarkHistory::query()->create([
            'compiled_mark_id' => $compiledMark->id,
            'version_no' => $nextVersion,
            'action' => $action,
            'enrollment_id' => (int) $compiledMark->enrollment_id,
            'subject_id' => (int) $compiledMark->subject_id,
            'section_id' => (int) $compiledMark->section_id,
            'academic_year_id' => (int) $compiledMark->academic_year_id,
            'exam_configuration_id' => $compiledMark->exam_configuration_id !== null ? (int) $compiledMark->exam_configuration_id : null,
            'exam_session_id' => $compiledMark->exam_session_id !== null ? (int) $compiledMark->exam_session_id : null,
            'marked_on' => $compiledMark->marked_on,
            'marks_obtained' => $compiledMark->marks_obtained,
            'max_marks' => $compiledMark->max_marks,
            'remarks' => $compiledMark->remarks,
            'is_finalized' => (bool) $compiledMark->is_finalized,
            'changed_by' => $userId,
            'changed_at' => $changedAt,
            'metadata' => !empty($metadata) ? $metadata : null,
        ]);
    }
}
