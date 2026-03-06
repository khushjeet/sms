<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompiledMark;
use App\Models\AcademicYearExamConfig;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\TeacherMark;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminMarksController extends Controller
{
    public function sheet(Request $request)
    {
        $this->requireSuperAdmin($request);

        $validated = $request->validate([
            'section_id' => ['required', 'integer', 'exists:sections,id'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'subject_code' => ['nullable', 'string', 'max:100'],
            'marked_on' => ['nullable', 'date'],
            'exam_configuration_id' => ['required', 'integer', 'exists:academic_year_exam_configs,id'],
        ]);

        $scope = $this->resolveScope(
            (int) $validated['section_id'],
            isset($validated['subject_id']) ? (int) $validated['subject_id'] : null,
            isset($validated['subject_code']) ? (string) $validated['subject_code'] : null
        );
        $examConfig = $this->resolveExamConfiguration(
            (int) $scope['academic_year_id'],
            (int) $validated['exam_configuration_id']
        );
        $markedOn = $validated['marked_on'] ?? now()->toDateString();

        $teachers = DB::table('teacher_subject_assignments as tsa')
            ->join('users as u', 'u.id', '=', 'tsa.teacher_id')
            ->where('tsa.subject_id', $scope['subject_id'])
            ->where('tsa.section_id', $scope['section_id'])
            ->where('tsa.academic_year_id', $scope['academic_year_id'])
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

        $rows = $this->sectionEnrollmentRows($scope['section_id']);
        $enrollmentIds = $rows->pluck('enrollment_id')->all();

        $teacherMarks = TeacherMark::query()
            ->where('subject_id', $scope['subject_id'])
            ->where('section_id', $scope['section_id'])
            ->where('academic_year_id', $scope['academic_year_id'])
            ->where('exam_configuration_id', (int) $examConfig->id)
            ->whereDate('marked_on', $markedOn)
            ->whereIn('enrollment_id', $enrollmentIds)
            ->get();

        $compiledMarks = CompiledMark::query()
            ->where('subject_id', $scope['subject_id'])
            ->where('section_id', $scope['section_id'])
            ->where('academic_year_id', $scope['academic_year_id'])
            ->where('exam_configuration_id', (int) $examConfig->id)
            ->whereDate('marked_on', $markedOn)
            ->whereIn('enrollment_id', $enrollmentIds)
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
        ]);
    }

    public function compile(Request $request)
    {
        $userId = $this->requireSuperAdmin($request);

        $validated = $request->validate([
            'section_id' => ['required', 'integer', 'exists:sections,id'],
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
            (int) $validated['section_id'],
            isset($validated['subject_id']) ? (int) $validated['subject_id'] : null,
            isset($validated['subject_code']) ? (string) $validated['subject_code'] : null
        );
        $examConfig = $this->resolveExamConfiguration(
            (int) $scope['academic_year_id'],
            (int) $validated['exam_configuration_id']
        );
        $markedOn = (string) $validated['marked_on'];

        $submittedEnrollmentIds = collect($validated['rows'])
            ->pluck('enrollment_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $allowedEnrollmentIds = Enrollment::query()
            ->where('section_id', $scope['section_id'])
            ->whereIn('id', $submittedEnrollmentIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $invalidEnrollmentIds = array_values(array_diff($submittedEnrollmentIds, $allowedEnrollmentIds));
        if (!empty($invalidEnrollmentIds)) {
            return response()->json([
                'message' => 'Some enrollments do not belong to this section.',
                'invalid_enrollment_ids' => $invalidEnrollmentIds,
            ], 422);
        }

        $finalizedIds = CompiledMark::query()
            ->where('subject_id', $scope['subject_id'])
            ->where('section_id', $scope['section_id'])
            ->where('academic_year_id', $scope['academic_year_id'])
            ->where('exam_configuration_id', (int) $examConfig->id)
            ->whereDate('marked_on', $markedOn)
            ->whereIn('enrollment_id', $submittedEnrollmentIds)
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

                CompiledMark::query()->updateOrCreate(
                    [
                        'enrollment_id' => (int) $row['enrollment_id'],
                        'subject_id' => $scope['subject_id'],
                        'section_id' => $scope['section_id'],
                        'academic_year_id' => $scope['academic_year_id'],
                        'exam_configuration_id' => (int) $examConfig->id,
                        'marked_on' => $markedOn,
                    ],
                    [
                        'marks_obtained' => $marksObtained,
                        'max_marks' => $maxMarks,
                        'remarks' => $row['remarks'] ?? null,
                        'compiled_by' => $userId,
                        'compiled_at' => $now,
                        'is_finalized' => false,
                        'finalized_by' => null,
                        'finalized_at' => null,
                    ]
                );
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
            'section_id' => ['required', 'integer', 'exists:sections,id'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'subject_code' => ['nullable', 'string', 'max:100'],
            'marked_on' => ['required', 'date'],
            'exam_configuration_id' => ['required', 'integer', 'exists:academic_year_exam_configs,id'],
        ]);

        $scope = $this->resolveScope(
            (int) $validated['section_id'],
            isset($validated['subject_id']) ? (int) $validated['subject_id'] : null,
            isset($validated['subject_code']) ? (string) $validated['subject_code'] : null
        );
        $examConfig = $this->resolveExamConfiguration(
            (int) $scope['academic_year_id'],
            (int) $validated['exam_configuration_id']
        );
        $markedOn = (string) $validated['marked_on'];

        $query = CompiledMark::query()
            ->where('subject_id', $scope['subject_id'])
            ->where('section_id', $scope['section_id'])
            ->where('academic_year_id', $scope['academic_year_id'])
            ->where('exam_configuration_id', (int) $examConfig->id)
            ->whereDate('marked_on', $markedOn);

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

        if ($examSession->name !== $sessionName) {
            $examSession->name = $sessionName;
            $examSession->save();
        }

        $affected = $query->update([
            'is_finalized' => true,
            'finalized_by' => $userId,
            'finalized_at' => now(),
            'exam_session_id' => $examSession->id,
        ]);

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

    private function resolveScope(int $sectionId, ?int $subjectId, ?string $subjectCode): array
    {
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
                'ay.name as academic_year_name'
            )
            ->first();

        if (!$section) {
            abort(404, 'Section not found.');
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

        $mappedMaxMarks = DB::table('class_subjects')
            ->where('class_id', (int) $section->class_id)
            ->where('subject_id', (int) $subject->id)
            ->where('academic_year_id', (int) $section->academic_year_id)
            ->value('max_marks');

        return [
            'section_id' => (int) $section->section_id,
            'section_name' => $section->section_name,
            'class_id' => (int) $section->class_id,
            'class_name' => $section->class_name,
            'academic_year_id' => (int) $section->academic_year_id,
            'academic_year_name' => $section->academic_year_name,
            'subject_id' => (int) $subject->id,
            'subject_name' => $subject->name,
            'subject_code' => $subject->subject_code ?: $subject->code,
            'mapped_max_marks' => $mappedMaxMarks !== null ? (float) $mappedMaxMarks : null,
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

    private function sectionEnrollmentRows(int $sectionId): Collection
    {
        return Enrollment::query()
            ->with('student.user')
            ->where('section_id', $sectionId)
            ->where('status', 'active')
            ->orderBy('roll_number')
            ->get()
            ->map(fn (Enrollment $enrollment) => [
                'enrollment_id' => (int) $enrollment->id,
                'student_id' => (int) $enrollment->student_id,
                'roll_number' => $enrollment->roll_number,
                'student_name' => $enrollment->student?->full_name,
            ])
            ->values();
    }
}
