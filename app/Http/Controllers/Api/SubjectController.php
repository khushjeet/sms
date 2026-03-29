<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\AcademicYearExamConfig;
use App\Models\ClassModel;
use App\Models\Section;
use App\Models\Subject;
use App\Services\Email\EventNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SubjectController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Subject::query();

        if ($user && $user->hasRole('teacher')) {
            $query->whereExists(function ($subQuery) use ($user) {
                $subQuery->selectRaw('1')
                    ->from('teacher_subject_assignments as tsa')
                    ->whereColumn('tsa.subject_id', 'subjects.id')
                    ->where('tsa.teacher_id', $user->id);
            });
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';

            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('subject_code', 'like', $like)
                    ->orWhere('short_name', 'like', $like);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->status);
        }

        if ($request->filled('type')) {
            $query->where('type', (string) $request->type);
        }

        if ($request->filled('is_active')) {
            $isActive = filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isActive !== null) {
                $query->where('is_active', $isActive);
            }
        }

        if ($request->filled('class_id') || $request->filled('academic_year_id')) {
            $classId = $request->filled('class_id') ? (int) $request->input('class_id') : null;
            $academicYearId = $request->filled('academic_year_id') ? (int) $request->input('academic_year_id') : null;

            $query->whereExists(function ($subQuery) use ($classId, $academicYearId) {
                $subQuery->selectRaw('1')
                    ->from('class_subjects as cs')
                    ->whereColumn('cs.subject_id', 'subjects.id');

                if ($classId !== null) {
                    $subQuery->where('cs.class_id', $classId);
                }

                if ($academicYearId !== null) {
                    $subQuery->where('cs.academic_year_id', $academicYearId);
                }
            });
        }

        $perPage = (int) $request->input('per_page', 15);
        $perPage = max(1, min($perPage, 200));

        return response()->json(
            $query->orderBy('name', 'asc')->paginate($perPage)
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'subject_code' => 'required|string|max:100|alpha_dash|unique:subjects,subject_code',
            'short_name' => 'nullable|string|max:50',
            'type' => ['sometimes', Rule::in(['core', 'elective', 'optional'])],
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
            'credits' => 'nullable|integer|min:0|max:99',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'board_code' => 'nullable|string|max:100',
            'lms_code' => 'nullable|string|max:100',
            'erp_code' => 'nullable|string|max:100',
        ]);

        $subjectCode = strtoupper((string) $validated['subject_code']);
        $isActive = array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true;

        $subject = Subject::create([
            'name' => $validated['name'],
            'code' => $subjectCode,
            'subject_code' => $subjectCode,
            'short_name' => $validated['short_name'] ?? null,
            'type' => $validated['type'] ?? 'core',
            'category' => $validated['type'] ?? 'core',
            'description' => $validated['description'] ?? null,
            'status' => $isActive ? 'active' : 'inactive',
            'is_active' => $isActive,
            'credits' => $validated['credits'] ?? null,
            'effective_from' => $validated['effective_from'] ?? null,
            'effective_to' => $validated['effective_to'] ?? null,
            'board_code' => $validated['board_code'] ?? null,
            'lms_code' => $validated['lms_code'] ?? null,
            'erp_code' => $validated['erp_code'] ?? null,
        ]);

        return response()->json([
            'message' => 'Subject created successfully',
            'data' => $subject,
        ], 201);
    }

    public function show($id)
    {
        $user = request()->user();
        if ($user && $user->hasRole('teacher')) {
            $isAssigned = DB::table('teacher_subject_assignments')
                ->where('subject_id', (int) $id)
                ->where('teacher_id', $user->id)
                ->exists();

            if (!$isAssigned) {
                return response()->json([
                    'message' => 'You can only view subjects allotted to you.',
                ], 403);
            }
        }

        $subject = Subject::query()
            ->with([
                'classes' => function ($query) {
                    $query->select('classes.id', 'classes.name', 'classes.numeric_order')
                        ->orderBy('classes.numeric_order')
                        ->orderBy('classes.name');
                },
            ])
            ->findOrFail($id);

        $examConfigIds = collect($subject->classes)
            ->pluck('pivot.academic_year_exam_config_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $examConfigNameMap = $examConfigIds->isEmpty()
            ? collect()
            : AcademicYearExamConfig::query()
                ->whereIn('id', $examConfigIds->all())
                ->pluck('name', 'id');

        $subject->classes->each(function ($mapping) use ($examConfigNameMap) {
            $configId = $mapping->pivot->academic_year_exam_config_id !== null
                ? (int) $mapping->pivot->academic_year_exam_config_id
                : null;

            $mapping->pivot->academic_year_exam_config_name = $configId !== null
                ? $examConfigNameMap->get($configId)
                : null;
        });

        return response()->json($subject);
    }

    public function storeClassMapping(Request $request, $id)
    {
        $subject = Subject::findOrFail($id);

        $validated = $request->validate([
            'class_id' => 'required|integer|exists:classes,id',
            'academic_year_id' => 'required|integer|exists:academic_years,id',
            'academic_year_exam_config_id' => 'nullable|integer|exists:academic_year_exam_configs,id',
            'max_marks' => 'required|integer|min:1|max:1000',
            'pass_marks' => 'required|integer|min:0|max:1000|lte:max_marks',
            'is_mandatory' => 'sometimes|boolean',
        ]);

        $class = ClassModel::findOrFail((int) $validated['class_id']);
        $academicYear = AcademicYear::findOrFail((int) $validated['academic_year_id']);
        $examConfigId = isset($validated['academic_year_exam_config_id'])
            ? (int) $validated['academic_year_exam_config_id']
            : null;
        $examConfig = $examConfigId !== null
            ? AcademicYearExamConfig::query()->findOrFail($examConfigId)
            : null;
        if ($examConfig && (int) $examConfig->academic_year_id !== (int) $academicYear->id) {
            return response()->json([
                'message' => 'Selected exam configuration does not belong to selected academic year.',
            ], 422);
        }

        $now = now();

        DB::transaction(function () use ($subject, $validated, $now, $examConfigId) {
            DB::table('class_subjects')->upsert(
                [[
                    'class_id' => (int) $validated['class_id'],
                    'subject_id' => $subject->id,
                    'academic_year_id' => (int) $validated['academic_year_id'],
                    'academic_year_exam_config_id' => $examConfigId,
                    'max_marks' => (int) $validated['max_marks'],
                    'pass_marks' => (int) $validated['pass_marks'],
                    'is_mandatory' => (bool) ($validated['is_mandatory'] ?? true),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                ['class_id', 'subject_id', 'academic_year_id'],
                ['academic_year_exam_config_id', 'max_marks', 'pass_marks', 'is_mandatory', 'updated_at']
            );
        });

        return response()->json([
            'message' => 'Subject mapped to class successfully',
            'data' => [
                'subject_id' => $subject->id,
                'class_id' => $class->id,
                'class_name' => $class->name,
                'academic_year_id' => $academicYear->id,
                'academic_year_name' => $academicYear->name,
                'academic_year_exam_config_id' => $examConfig?->id,
                'academic_year_exam_config_name' => $examConfig?->name,
                'max_marks' => (int) $validated['max_marks'],
                'pass_marks' => (int) $validated['pass_marks'],
                'is_mandatory' => (bool) ($validated['is_mandatory'] ?? true),
            ],
        ]);
    }

    public function destroyClassMapping($id, $classId, $academicYearId)
    {
        Subject::findOrFail($id);

        $classId = (int) $classId;
        $academicYearId = (int) $academicYearId;

        if (!ClassModel::query()->whereKey($classId)->exists()) {
            abort(404, 'Class not found');
        }

        if (!AcademicYear::query()->whereKey($academicYearId)->exists()) {
            abort(404, 'Academic year not found');
        }

        $deleted = DB::table('class_subjects')
            ->where('subject_id', (int) $id)
            ->where('class_id', $classId)
            ->where('academic_year_id', $academicYearId)
            ->delete();

        if ($deleted === 0) {
            return response()->json([
                'message' => 'Mapping not found',
            ], 404);
        }

        return response()->json([
            'message' => 'Subject mapping removed successfully',
        ]);
    }

    public function teacherAssignments(Request $request, int $id)
    {
        Subject::query()->findOrFail($id);
        $user = $request->user();

        $validated = $request->validate([
            'academic_year_id' => 'nullable|integer|exists:academic_years,id',
            'section_id' => 'nullable|integer|exists:sections,id',
            'teacher_id' => 'nullable|integer|exists:users,id',
        ]);

        $query = DB::table('teacher_subject_assignments as tsa')
            ->join('users as u', 'u.id', '=', 'tsa.teacher_id')
            ->leftJoin('sections as s', 's.id', '=', 'tsa.section_id')
            ->join('classes as c', 'c.id', '=', 'tsa.class_id')
            ->join('academic_years as ay', 'ay.id', '=', 'tsa.academic_year_id')
            ->leftJoin('academic_year_exam_configs as aec', 'aec.id', '=', 'tsa.academic_year_exam_config_id')
            ->where('tsa.subject_id', $id)
            ->select(
                'tsa.id',
                'tsa.subject_id',
                'tsa.class_id',
                'tsa.teacher_id',
                'tsa.section_id',
                'tsa.academic_year_id',
                'tsa.academic_year_exam_config_id',
                'tsa.created_at',
                'u.first_name',
                'u.last_name',
                'u.email as teacher_email',
                's.name as section_name',
                'c.name as class_name',
                'ay.name as academic_year_name',
                'aec.name as academic_year_exam_config_name'
            )
            ->orderByDesc('tsa.id');

        if (!empty($validated['academic_year_id'])) {
            $query->where('tsa.academic_year_id', (int) $validated['academic_year_id']);
        }
        if (!empty($validated['section_id'])) {
            $query->where('tsa.section_id', (int) $validated['section_id']);
        }
        if ($user && $user->hasRole('teacher')) {
            $query->where('tsa.teacher_id', $user->id);
        } elseif (!empty($validated['teacher_id'])) {
            $query->where('tsa.teacher_id', (int) $validated['teacher_id']);
        }

        $rows = $query->get()->map(function ($row) {
            return [
                'id' => (int) $row->id,
                'subject_id' => (int) $row->subject_id,
                'class_id' => (int) $row->class_id,
                'teacher_id' => (int) $row->teacher_id,
                'section_id' => $row->section_id !== null ? (int) $row->section_id : null,
                'academic_year_id' => (int) $row->academic_year_id,
                'academic_year_exam_config_id' => $row->academic_year_exam_config_id !== null ? (int) $row->academic_year_exam_config_id : null,
                'teacher_name' => trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')),
                'teacher_email' => $row->teacher_email,
                'section_name' => $row->section_name,
                'class_name' => $row->class_name,
                'academic_year_name' => $row->academic_year_name,
                'academic_year_exam_config_name' => $row->academic_year_exam_config_name,
                'created_at' => $row->created_at,
            ];
        })->values();

        return response()->json($rows);
    }

    public function storeTeacherAssignments(Request $request, int $id)
    {
        Subject::query()->findOrFail($id);

        $validated = $request->validate([
            'teacher_ids' => 'required|array|min:1|max:100',
            'teacher_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('users', 'id')->where(function ($query) {
                    $query->whereIn('role', ['teacher', 'staff']);
                }),
            ],
            'class_id' => 'required|integer|exists:classes,id',
            'section_id' => 'nullable|integer|exists:sections,id',
            'academic_year_id' => 'required|integer|exists:academic_years,id',
            'academic_year_exam_config_id' => 'required|integer|exists:academic_year_exam_configs,id',
        ]);

        $section = !empty($validated['section_id'])
            ? Section::query()->findOrFail((int) $validated['section_id'])
            : null;
        if ($section && (int) $section->academic_year_id !== (int) $validated['academic_year_id']) {
            return response()->json([
                'message' => 'Selected section does not belong to selected academic year.',
            ], 422);
        }
        if ($section && (int) $section->class_id !== (int) $validated['class_id']) {
            return response()->json([
                'message' => 'Selected section does not belong to selected class.',
            ], 422);
        }
        $examConfig = AcademicYearExamConfig::query()->findOrFail((int) $validated['academic_year_exam_config_id']);
        if ((int) $examConfig->academic_year_id !== (int) $validated['academic_year_id']) {
            return response()->json([
                'message' => 'Selected exam configuration does not belong to selected academic year.',
            ], 422);
        }

        $classSubjectMapping = DB::table('class_subjects')
            ->where('class_id', (int) $validated['class_id'])
            ->where('subject_id', $id)
            ->where('academic_year_id', (int) $validated['academic_year_id'])
            ->first();

        if (!$classSubjectMapping) {
            return response()->json([
                'message' => 'Create Subject Class Assignment for this class, subject, and academic year first.',
            ], 422);
        }

        if ($classSubjectMapping->academic_year_exam_config_id !== null
            && (int) $classSubjectMapping->academic_year_exam_config_id !== (int) $validated['academic_year_exam_config_id']) {
            return response()->json([
                'message' => 'Selected exam configuration must match Subject Class Assignment exam configuration.',
            ], 422);
        }

        $now = now();
        $rows = collect($validated['teacher_ids'])
            ->map(fn ($teacherId) => [
                'teacher_id' => (int) $teacherId,
                'class_id' => (int) $validated['class_id'],
                'section_id' => !empty($validated['section_id']) ? (int) $validated['section_id'] : null,
                'subject_id' => $id,
                'academic_year_id' => (int) $validated['academic_year_id'],
                'academic_year_exam_config_id' => (int) $validated['academic_year_exam_config_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        foreach ($rows as $row) {
            DB::table('teacher_subject_assignments')->updateOrInsert(
                [
                    'teacher_id' => $row['teacher_id'],
                    'class_id' => $row['class_id'],
                    'section_id' => $row['section_id'],
                    'subject_id' => $row['subject_id'],
                    'academic_year_id' => $row['academic_year_id'],
                ],
                [
                    'academic_year_exam_config_id' => $row['academic_year_exam_config_id'],
                    'updated_at' => $row['updated_at'],
                    'created_at' => $row['created_at'],
                ]
            );
        }

        app(EventNotificationService::class)->notifyTeacherSubjectAssigned(
            Subject::query()->findOrFail($id),
            array_map('intval', $validated['teacher_ids']),
            (int) $validated['class_id'],
            !empty($validated['section_id']) ? (int) $validated['section_id'] : null,
            (int) $validated['academic_year_id']
        );

        return response()->json([
            'message' => 'Teacher assignments saved successfully.',
            'data' => [
                'subject_id' => $id,
                'teacher_ids' => array_map('intval', $validated['teacher_ids']),
                'class_id' => (int) $validated['class_id'],
                'section_id' => !empty($validated['section_id']) ? (int) $validated['section_id'] : null,
                'academic_year_id' => (int) $validated['academic_year_id'],
                'academic_year_exam_config_id' => (int) $validated['academic_year_exam_config_id'],
            ],
        ], 201);
    }

    public function destroyTeacherAssignment(int $id, int $assignmentId)
    {
        Subject::query()->findOrFail($id);

        $deleted = DB::table('teacher_subject_assignments')
            ->where('subject_id', $id)
            ->where('id', $assignmentId)
            ->delete();

        if ($deleted === 0) {
            return response()->json([
                'message' => 'Teacher assignment not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Teacher assignment removed successfully.',
        ]);
    }

    public function update(Request $request, $id)
    {
        $subject = Subject::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'subject_code' => ['sometimes', 'string', 'max:100', 'alpha_dash', Rule::unique('subjects', 'subject_code')->ignore($id)],
            'short_name' => 'nullable|string|max:50',
            'type' => ['sometimes', Rule::in(['core', 'elective', 'optional'])],
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
            'credits' => 'nullable|integer|min:0|max:99',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'board_code' => 'nullable|string|max:100',
            'lms_code' => 'nullable|string|max:100',
            'erp_code' => 'nullable|string|max:100',
        ]);

        if (array_key_exists('subject_code', $validated)) {
            $validated['subject_code'] = strtoupper((string) $validated['subject_code']);
            $validated['code'] = $validated['subject_code']; // compatibility for old consumers
        }

        if (array_key_exists('type', $validated)) {
            $validated['category'] = $validated['type'];
        }

        if (array_key_exists('is_active', $validated)) {
            $validated['status'] = $validated['is_active'] ? 'active' : 'inactive';
        }

        $subject->update($validated);

        return response()->json([
            'message' => 'Subject updated successfully',
            'data' => $subject->fresh(),
        ]);
    }

    public function destroy($id)
    {
        $subject = Subject::findOrFail($id);

        $blockingRefs = [
            'class_subjects' => DB::table('class_subjects')->where('subject_id', $subject->id)->count(),
            'teacher_subject_assignments' => DB::table('teacher_subject_assignments')->where('subject_id', $subject->id)->count(),
            'exam_schedules' => DB::table('exam_schedules')->where('subject_id', $subject->id)->count(),
            'results' => DB::table('results')->where('subject_id', $subject->id)->count(),
            'timetables' => DB::table('timetables')->where('subject_id', $subject->id)->count(),
        ];

        $activeDependencies = array_filter($blockingRefs, fn ($count) => $count > 0);
        if (!empty($activeDependencies)) {
            return response()->json([
                'message' => 'Cannot delete subject with existing references. Deactivate it instead.',
                'dependencies' => $activeDependencies,
            ], 422);
        }

        $subject->delete();

        return response()->json([
            'message' => 'Subject deleted successfully',
        ]);
    }
}
