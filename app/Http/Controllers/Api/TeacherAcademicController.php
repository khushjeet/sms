<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYearExamConfig;
use App\Models\Attendance;
use App\Models\Enrollment;
use App\Models\TeacherMark;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TeacherAcademicController extends Controller
{
    public function assignments(Request $request)
    {
        $teacherId = $this->requireTeacher($request);

        $rows = DB::table('teacher_subject_assignments as tsa')
            ->join('subjects as sub', 'sub.id', '=', 'tsa.subject_id')
            ->join('sections as sec', 'sec.id', '=', 'tsa.section_id')
            ->join('classes as cls', 'cls.id', '=', 'sec.class_id')
            ->join('academic_years as ay', 'ay.id', '=', 'tsa.academic_year_id')
            ->leftJoin('academic_year_exam_configs as tsaec', 'tsaec.id', '=', 'tsa.academic_year_exam_config_id')
            ->leftJoin('class_subjects as cs', function ($join) {
                $join->on('cs.subject_id', '=', 'tsa.subject_id')
                    ->on('cs.class_id', '=', 'sec.class_id')
                    ->on('cs.academic_year_id', '=', 'tsa.academic_year_id');
            })
            ->leftJoin('academic_year_exam_configs as aec', 'aec.id', '=', 'cs.academic_year_exam_config_id')
            ->where('tsa.teacher_id', $teacherId)
            ->select(
                'tsa.id',
                'tsa.subject_id',
                'tsa.section_id',
                'tsa.academic_year_id',
                'sec.class_id',
                'sub.name as subject_name',
                'sub.subject_code',
                'sub.code',
                'sec.name as section_name',
                'cls.name as class_name',
                'ay.name as academic_year_name',
                'cs.max_marks as mapped_max_marks',
                'cs.pass_marks as mapped_pass_marks',
                'tsa.academic_year_exam_config_id as assigned_exam_configuration_id',
                'tsaec.name as assigned_exam_configuration_name',
                'cs.academic_year_exam_config_id as mapped_exam_configuration_id',
                'aec.name as mapped_exam_configuration_name'
            )
            ->orderBy('ay.name')
            ->orderBy('cls.name')
            ->orderBy('sec.name')
            ->orderBy('sub.name')
            ->get()
            ->map(function ($row) {
                return [
                    'id' => (int) $row->id,
                    'subject_id' => (int) $row->subject_id,
                    'section_id' => (int) $row->section_id,
                    'class_id' => (int) $row->class_id,
                    'academic_year_id' => (int) $row->academic_year_id,
                    'subject_name' => $row->subject_name,
                    'subject_code' => $row->subject_code ?: $row->code,
                    'section_name' => $row->section_name,
                    'class_name' => $row->class_name,
                    'academic_year_name' => $row->academic_year_name,
                    'mapped_max_marks' => $row->mapped_max_marks !== null ? (float) $row->mapped_max_marks : null,
                    'mapped_pass_marks' => $row->mapped_pass_marks !== null ? (float) $row->mapped_pass_marks : null,
                    'mapped_exam_configuration_id' => $row->assigned_exam_configuration_id !== null
                        ? (int) $row->assigned_exam_configuration_id
                        : ($row->mapped_exam_configuration_id !== null ? (int) $row->mapped_exam_configuration_id : null),
                    'mapped_exam_configuration_name' => $row->assigned_exam_configuration_name ?: $row->mapped_exam_configuration_name,
                ];
            })
            ->values();

        return response()->json($rows);
    }

    public function attendanceSheet(Request $request)
    {
        $teacherId = $this->requireTeacher($request);
        $validated = $request->validate([
            'assignment_id' => ['required', 'integer', 'exists:teacher_subject_assignments,id'],
            'date' => ['required', 'date'],
        ]);

        $assignment = $this->resolveAssignment($teacherId, (int) $validated['assignment_id']);
        $rows = $this->sectionEnrollmentRows((int) $assignment->section_id);
        $attendanceByEnrollment = Attendance::query()
            ->whereDate('date', $validated['date'])
            ->whereIn('enrollment_id', $rows->pluck('enrollment_id')->all())
            ->get()
            ->keyBy('enrollment_id');

        $sheet = $rows->map(function (array $row) use ($attendanceByEnrollment) {
            $attendance = $attendanceByEnrollment->get($row['enrollment_id']);
            return [
                ...$row,
                'status' => $attendance?->status ?? 'not_marked',
                'remarks' => $attendance?->remarks,
                'is_locked' => (bool) ($attendance?->is_locked ?? false),
            ];
        })->values();

        return response()->json($sheet);
    }

    public function saveAttendance(Request $request)
    {
        $teacherId = $this->requireTeacher($request);
        $validated = $request->validate([
            'assignment_id' => ['required', 'integer', 'exists:teacher_subject_assignments,id'],
            'date' => ['required', 'date'],
            'attendances' => ['required', 'array', 'min:1', 'max:200'],
            'attendances.*.enrollment_id' => ['required', 'integer', 'exists:enrollments,id'],
            'attendances.*.status' => ['required', Rule::in(['present', 'absent', 'leave', 'half_day'])],
            'attendances.*.remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $assignment = $this->resolveAssignment($teacherId, (int) $validated['assignment_id']);
        $allowedEnrollmentIds = Enrollment::query()
            ->where('section_id', (int) $assignment->section_id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $submittedIds = collect($validated['attendances'])
            ->pluck('enrollment_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $invalidIds = array_values(array_diff($submittedIds, $allowedEnrollmentIds));

        if (!empty($invalidIds)) {
            return response()->json([
                'message' => 'Some enrollments do not belong to your assigned section.',
                'invalid_enrollment_ids' => $invalidIds,
            ], 422);
        }

        DB::transaction(function () use ($validated, $teacherId) {
            foreach ($validated['attendances'] as $item) {
                $existing = Attendance::query()
                    ->where('enrollment_id', (int) $item['enrollment_id'])
                    ->whereDate('date', $validated['date'])
                    ->first();

                if ($existing?->is_locked) {
                    continue;
                }

                Attendance::query()->updateOrCreate(
                    [
                        'enrollment_id' => (int) $item['enrollment_id'],
                        'date' => $validated['date'],
                    ],
                    [
                        'status' => $item['status'],
                        'remarks' => $item['remarks'] ?? null,
                        'marked_by' => $teacherId,
                        'marked_at' => now(),
                    ]
                );
            }
        });

        return response()->json([
            'message' => 'Attendance saved successfully.',
        ]);
    }

    public function marksSheet(Request $request)
    {
        $teacherId = $this->requireTeacher($request);
        $validated = $request->validate([
            'assignment_id' => ['required', 'integer', 'exists:teacher_subject_assignments,id'],
            'marked_on' => ['nullable', 'date'],
            'exam_configuration_id' => ['required', 'integer', 'exists:academic_year_exam_configs,id'],
        ]);

        $assignment = $this->resolveAssignment($teacherId, (int) $validated['assignment_id']);
        $examConfig = $this->resolveExamConfiguration(
            (int) $assignment->academic_year_id,
            (int) $validated['exam_configuration_id']
        );
        $this->assertMappedExamConfiguration($assignment, (int) $examConfig->id);
        $markedOn = $validated['marked_on'] ?? now()->toDateString();
        $rows = $this->sectionEnrollmentRows((int) $assignment->section_id);

        $marks = TeacherMark::query()
            ->where('teacher_id', $teacherId)
            ->where('subject_id', (int) $assignment->subject_id)
            ->where('section_id', (int) $assignment->section_id)
            ->where('academic_year_id', (int) $assignment->academic_year_id)
            ->where('exam_configuration_id', (int) $examConfig->id)
            ->whereDate('marked_on', $markedOn)
            ->get()
            ->keyBy('enrollment_id');

        $sheet = $rows->map(function (array $row) use ($marks, $assignment) {
            $markRow = $marks->get($row['enrollment_id']);
            return [
                ...$row,
                'marks_obtained' => $markRow ? (float) $markRow->marks_obtained : null,
                'max_marks' => $markRow ? (float) $markRow->max_marks : ($assignment->mapped_max_marks !== null ? (float) $assignment->mapped_max_marks : 100.0),
                'remarks' => $markRow?->remarks,
            ];
        })->values();

        return response()->json([
            'marked_on' => $markedOn,
            'exam_configuration_id' => (int) $examConfig->id,
            'rows' => $sheet,
        ]);
    }

    public function saveMarks(Request $request)
    {
        $teacherId = $this->requireTeacher($request);
        $validated = $request->validate([
            'assignment_id' => ['required', 'integer', 'exists:teacher_subject_assignments,id'],
            'marked_on' => ['required', 'date'],
            'exam_configuration_id' => ['required', 'integer', 'exists:academic_year_exam_configs,id'],
            'marks' => ['required', 'array', 'min:1', 'max:200'],
            'marks.*.enrollment_id' => ['required', 'integer', 'exists:enrollments,id'],
            'marks.*.marks_obtained' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'marks.*.max_marks' => ['nullable', 'numeric', 'min:1', 'max:1000'],
            'marks.*.remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $assignment = $this->resolveAssignment($teacherId, (int) $validated['assignment_id']);
        $examConfig = $this->resolveExamConfiguration(
            (int) $assignment->academic_year_id,
            (int) $validated['exam_configuration_id']
        );
        $this->assertMappedExamConfiguration($assignment, (int) $examConfig->id);
        $allowedEnrollmentIds = Enrollment::query()
            ->where('section_id', (int) $assignment->section_id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $submittedIds = collect($validated['marks'])
            ->pluck('enrollment_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $invalidIds = array_values(array_diff($submittedIds, $allowedEnrollmentIds));
        if (!empty($invalidIds)) {
            return response()->json([
                'message' => 'Some enrollments do not belong to your assigned section.',
                'invalid_enrollment_ids' => $invalidIds,
            ], 422);
        }

        DB::transaction(function () use ($validated, $assignment, $examConfig, $teacherId) {
            foreach ($validated['marks'] as $item) {
                $maxMarks = array_key_exists('max_marks', $item) && $item['max_marks'] !== null
                    ? (float) $item['max_marks']
                    : (($assignment->mapped_max_marks !== null) ? (float) $assignment->mapped_max_marks : 100.0);
                $marksObtained = $item['marks_obtained'] !== null ? (float) $item['marks_obtained'] : null;

                if ($marksObtained !== null && $marksObtained > $maxMarks) {
                    continue;
                }

                TeacherMark::query()->updateOrCreate(
                    [
                        'enrollment_id' => (int) $item['enrollment_id'],
                        'subject_id' => (int) $assignment->subject_id,
                        'section_id' => (int) $assignment->section_id,
                        'academic_year_id' => (int) $assignment->academic_year_id,
                        'exam_configuration_id' => (int) $examConfig->id,
                        'teacher_id' => $teacherId,
                        'marked_on' => $validated['marked_on'],
                    ],
                    [
                        'marks_obtained' => $marksObtained,
                        'max_marks' => $maxMarks,
                        'remarks' => $item['remarks'] ?? null,
                    ]
                );
            }
        });

        return response()->json([
            'message' => 'Marks saved successfully.',
        ]);
    }

    private function requireTeacher(Request $request): int
    {
        $user = $request->user();
        if (!$user || !$user->hasRole('teacher')) {
            abort(403, 'Teacher access required.');
        }

        return (int) $user->id;
    }

    private function resolveAssignment(int $teacherId, int $assignmentId): object
    {
        $assignment = DB::table('teacher_subject_assignments as tsa')
            ->join('sections as sec', 'sec.id', '=', 'tsa.section_id')
            ->leftJoin('class_subjects as cs', function ($join) {
                $join->on('cs.subject_id', '=', 'tsa.subject_id')
                    ->on('cs.class_id', '=', 'sec.class_id')
                    ->on('cs.academic_year_id', '=', 'tsa.academic_year_id');
            })
            ->where('tsa.id', $assignmentId)
            ->where('tsa.teacher_id', $teacherId)
            ->select(
                'tsa.id',
                'tsa.teacher_id',
                'tsa.subject_id',
                'tsa.section_id',
                'tsa.academic_year_id',
                'cs.max_marks as mapped_max_marks',
                'tsa.academic_year_exam_config_id as assigned_exam_configuration_id',
                'cs.academic_year_exam_config_id as mapped_exam_configuration_id'
            )
            ->first();

        if (!$assignment) {
            abort(403, 'This subject assignment is not allotted to you.');
        }

        return $assignment;
    }

    private function resolveExamConfiguration(int $academicYearId, int $examConfigurationId): AcademicYearExamConfig
    {
        $examConfig = AcademicYearExamConfig::query()->findOrFail($examConfigurationId);

        if ((int) $examConfig->academic_year_id !== $academicYearId) {
            abort(422, 'Selected exam does not belong to your assignment academic year.');
        }

        if (!$examConfig->is_active) {
            abort(422, 'Selected exam is inactive. Contact super admin.');
        }

        return $examConfig;
    }

    private function assertMappedExamConfiguration(object $assignment, int $examConfigurationId): void
    {
        if (isset($assignment->mapped_exam_configuration_id)
            && isset($assignment->assigned_exam_configuration_id)
            && $assignment->assigned_exam_configuration_id !== null
            && (int) $assignment->assigned_exam_configuration_id !== $examConfigurationId) {
            abort(422, 'Selected exam does not match assigned teacher exam configuration.');
        }

        if (isset($assignment->mapped_exam_configuration_id)
            && $assignment->mapped_exam_configuration_id !== null
            && (int) $assignment->mapped_exam_configuration_id !== $examConfigurationId) {
            abort(422, 'Selected exam does not match mapped subject class assignment exam configuration.');
        }
    }

    private function sectionEnrollmentRows(int $sectionId): Collection
    {
        return Enrollment::query()
            ->with('student.user')
            ->where('section_id', $sectionId)
            ->where('status', 'active')
            ->orderBy('roll_number')
            ->get()
            ->map(function (Enrollment $enrollment) {
                return [
                    'enrollment_id' => (int) $enrollment->id,
                    'student_id' => (int) $enrollment->student_id,
                    'roll_number' => $enrollment->roll_number,
                    'student_name' => $enrollment->student?->full_name,
                ];
            })
            ->values();
    }
}
