<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamSession;
use App\Models\GradingScheme;
use App\Models\SchoolSetting;
use App\Models\ResultAuditLog;
use App\Models\ResultMarkSnapshot;
use App\Models\ResultVisibilityControl;
use App\Models\StudentResult;
use App\Models\VisibilityAuditLog;
use App\Services\Email\EventNotificationService;
use App\Services\InAppNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResultPublishingController extends Controller
{
    private const HIDDEN_RESULT_MESSAGE = 'Result is not available for you currently. Please contact administration.';

    public function sessions(Request $request)
    {
        $query = ExamSession::query()
            ->with(['academicYear:id,name', 'classModel:id,name'])
            ->orderByDesc('id');

        if ($request->filled('academic_year_id')) {
            $query->where('academic_year_id', (int) $request->input('academic_year_id'));
        }
        if ($request->filled('class_id')) {
            $query->where('class_id', (int) $request->input('class_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        return response()->json($query->paginate((int) $request->input('per_page', 20)));
    }

    public function createSession(Request $request)
    {
        $userId = $this->requireSuperAdmin($request);

        $validated = $request->validate([
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'class_id' => ['required', 'integer', 'exists:classes,id'],
            'exam_configuration_id' => ['nullable', 'integer', 'exists:academic_year_exam_configs,id'],
            'name' => ['required', 'string', 'max:150'],
            'status' => ['nullable', 'in:draft,compiling,published,locked'],
        ]);

        if (!empty($validated['exam_configuration_id'])) {
            $belongsToYear = DB::table('academic_year_exam_configs')
                ->where('id', (int) $validated['exam_configuration_id'])
                ->where('academic_year_id', (int) $validated['academic_year_id'])
                ->exists();

            if (!$belongsToYear) {
                return response()->json(['message' => 'Selected exam configuration does not belong to selected academic year.'], 422);
            }
        }

        $session = ExamSession::query()->create([
            'academic_year_id' => (int) $validated['academic_year_id'],
            'class_id' => (int) $validated['class_id'],
            'exam_configuration_id' => isset($validated['exam_configuration_id']) ? (int) $validated['exam_configuration_id'] : null,
            'name' => trim((string) $validated['name']),
            'class_name_snapshot' => (string) DB::table('classes')->where('id', (int) $validated['class_id'])->value('name'),
            'exam_name_snapshot' => trim((string) $validated['name']),
            'academic_year_label_snapshot' => (string) DB::table('academic_years')->where('id', (int) $validated['academic_year_id'])->value('name'),
            'school_snapshot' => $this->buildSchoolSnapshot(),
            'status' => $validated['status'] ?? 'draft',
            'created_by' => $userId,
        ]);

        $resolvedRank = $result->rank ?? $this->resolvePublishedRank($result);

        // Student photo/avatar is fixed temporarily for the result paper.
        // Later we can switch this back to the dynamic student profile photo flow.
        $fixedStudentAvatar = 'students/avatars/uq3WKpY7czm88YHNAkMHhjOWeecHn9FEhok5Qi3H.jpg';

        return response()->json([
            'message' => 'Exam session created.',
            'session' => $session,
        ], 201);
    }

    public function publish(Request $request)
    {
        $userId = $this->requireSuperAdmin($request);

        $validated = $request->validate(
            [
                'exam_session_id' => ['required', 'integer', 'exists:exam_sessions,id'],
                'reason' => ['nullable', 'string', 'max:1000'],
                'rows' => ['required', 'array', 'min:1', 'max:500'],
                'rows.*.enrollment_id' => ['required', 'integer', 'exists:enrollments,id'],
                'rows.*.result_status' => ['nullable', 'in:pass,fail,compartment'],
                'rows.*.remarks' => ['nullable', 'string', 'max:1000'],
                'rows.*.grade' => ['nullable', 'string', 'max:10'],
                'rows.*.subjects' => ['required', 'array', 'min:1', 'max:30'],
                'rows.*.subjects.*.subject_id' => ['required', 'integer', 'exists:subjects,id'],
                'rows.*.subjects.*.obtained_marks' => ['required', 'numeric', 'min:0', 'max:1000'],
                'rows.*.subjects.*.max_marks' => ['required', 'numeric', 'min:1', 'max:1000'],
                'rows.*.subjects.*.grade' => ['nullable', 'string', 'max:10'],
                'rows.*.subjects.*.teacher_id' => ['nullable', 'integer', 'exists:users,id'],
            ],
            [
                'exam_session_id.required' => 'Session is required.',
            ]
        );

        $session = ExamSession::query()->findOrFail((int) $validated['exam_session_id']);
        if ($session->status === 'locked') {
            return response()->json(['message' => 'Exam session is locked. Publishing is not allowed.'], 422);
        }
        $this->ensureSessionIdentityLocked($session);

        $publishedRows = $this->persistPublishedRows(
            $request,
            $session,
            $userId,
            $validated['rows'],
            $validated['reason'] ?? null
        );

        return response()->json([
            'message' => 'Results published successfully.',
            'exam_session_id' => $session->id,
            'published_count' => count($publishedRows),
            'rows' => $publishedRows,
        ]);
    }

    public function publishClassWise(Request $request)
    {
        $userId = $this->requireSuperAdmin($request);

        $validated = $request->validate(
            [
                'exam_session_id' => ['required', 'integer', 'exists:exam_sessions,id'],
                'class_id' => ['nullable', 'integer', 'exists:classes,id'],
                'marked_on' => ['nullable', 'date'],
                'reason' => ['nullable', 'string', 'max:1000'],
            ],
            [
                'exam_session_id.required' => 'Session is required.',
            ]
        );

        $session = ExamSession::query()->findOrFail((int) $validated['exam_session_id']);
        if ($session->status === 'locked') {
            return response()->json(['message' => 'Exam session is locked. Publishing is not allowed.'], 422);
        }
        $this->ensureSessionIdentityLocked($session);

        if (isset($validated['class_id']) && (int) $validated['class_id'] !== (int) $session->class_id) {
            return response()->json(['message' => 'Selected class does not match exam session class.'], 422);
        }

        $sectionIds = DB::table('sections')
            ->where('class_id', (int) $session->class_id)
            ->where('academic_year_id', (int) $session->academic_year_id)
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->all();

        if (empty($sectionIds)) {
            return response()->json(['message' => 'No sections found for this class and academic year.'], 422);
        }

        $activeEnrollmentIds = DB::table('enrollments')
            ->where('class_id', (int) $session->class_id)
            ->where('academic_year_id', (int) $session->academic_year_id)
            ->where('status', 'active')
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->all();

        if (empty($activeEnrollmentIds)) {
            return response()->json(['message' => 'No active enrollments found for selected class.'], 422);
        }

        $compiled = DB::table('compiled_marks')
            ->where('exam_session_id', (int) $session->id)
            ->where('is_finalized', true)
            ->whereIn('section_id', $sectionIds)
            ->whereIn('enrollment_id', $activeEnrollmentIds)
            ->select('enrollment_id', 'subject_id', 'marks_obtained', 'max_marks', 'remarks')
            ->get();

        // Backward-compatible fallback for legacy finalized rows without exam_session_id.
        if ($compiled->isEmpty() && !empty($validated['marked_on'])) {
            $markedOn = (string) $validated['marked_on'];
            $compiled = DB::table('compiled_marks')
                ->where('academic_year_id', (int) $session->academic_year_id)
                ->whereDate('marked_on', $markedOn)
                ->where('is_finalized', true)
                ->whereIn('section_id', $sectionIds)
                ->whereIn('enrollment_id', $activeEnrollmentIds)
                ->select('enrollment_id', 'subject_id', 'marks_obtained', 'max_marks', 'remarks')
                ->get();
        }

        if ($compiled->isEmpty()) {
            return response()->json(['message' => 'No finalized compiled marks found for class/date.'], 422);
        }

        $invalidRows = $compiled->filter(fn($row) => $row->max_marks === null || (float) $row->max_marks <= 0);
        if ($invalidRows->isNotEmpty()) {
            return response()->json([
                'message' => 'Some compiled marks rows are incomplete (null max). Complete them before publishing.',
                'invalid_count' => $invalidRows->count(),
            ], 422);
        }

        $grouped = $compiled->groupBy('enrollment_id');
        $missingEnrollmentIds = array_values(array_diff($activeEnrollmentIds, $grouped->keys()->map(fn($id) => (int) $id)->all()));
        if (!empty($missingEnrollmentIds)) {
            $expectedSubjects = DB::table('class_subjects as cs')
                ->join('subjects as sub', 'sub.id', '=', 'cs.subject_id')
                ->where('cs.class_id', (int) $session->class_id)
                ->where('cs.academic_year_id', (int) $session->academic_year_id)
                ->where(function ($query) use ($session) {
                    $query->whereNull('cs.academic_year_exam_config_id')
                        ->orWhere('cs.academic_year_exam_config_id', (int) $session->exam_configuration_id);
                })
                ->select('cs.subject_id', 'sub.name as subject_name', 'sub.subject_code', 'sub.code')
                ->orderBy('sub.name')
                ->get();

            $compiledSubjectIdsByEnrollment = $compiled
                ->groupBy('enrollment_id')
                ->map(fn($rows) => collect($rows)->pluck('subject_id')->map(fn($id) => (int) $id)->unique()->values()->all());

            $missingStudents = DB::table('enrollments as e')
                ->join('students as s', 's.id', '=', 'e.student_id')
                ->join('users as u', 'u.id', '=', 's.user_id')
                ->leftJoin('sections as sec', 'sec.id', '=', 'e.section_id')
                ->whereIn('e.id', $missingEnrollmentIds)
                ->select(
                    'e.id as enrollment_id',
                    'e.roll_number',
                    's.admission_number',
                    'u.first_name',
                    'u.last_name',
                    'sec.name as section_name'
                )
                ->orderBy('e.roll_number')
                ->orderBy('u.first_name')
                ->orderBy('u.last_name')
                ->get()
                ->map(function ($row) use ($expectedSubjects, $compiledSubjectIdsByEnrollment) {
                    $compiledSubjectIds = $compiledSubjectIdsByEnrollment->get((int) $row->enrollment_id, []);
                    $missingSubjects = $expectedSubjects
                        ->filter(fn($subject) => !in_array((int) $subject->subject_id, $compiledSubjectIds, true))
                        ->map(fn($subject) => [
                            'subject_id' => (int) $subject->subject_id,
                            'subject_name' => $subject->subject_name,
                            'subject_code' => $subject->subject_code ?: $subject->code,
                        ])
                        ->values();

                    return [
                        'enrollment_id' => (int) $row->enrollment_id,
                        'roll_number' => $row->roll_number,
                        'admission_number' => $row->admission_number,
                        'student_name' => trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')),
                        'section_name' => $row->section_name,
                        'missing_subjects' => $missingSubjects,
                    ];
                })
                ->values();

            return response()->json([
                'message' => 'Some active students are missing compiled/finalized marks for selected date.',
                'missing_enrollment_ids' => $missingEnrollmentIds,
                'missing_students' => $missingStudents,
                'exam_configuration_id' => (int) $session->exam_configuration_id,
            ], 422);
        }

        $passMarksBySubject = DB::table('class_subjects')
            ->where('class_id', (int) $session->class_id)
            ->where('academic_year_id', (int) $session->academic_year_id)
            ->pluck('pass_marks', 'subject_id')
            ->map(fn($marks) => $marks !== null ? (float) $marks : null);

        $subjectSnapshotMap = DB::table('subjects')
            ->whereIn('id', $compiled->pluck('subject_id')->map(fn($id) => (int) $id)->unique()->all())
            ->select('id', 'name', 'subject_code', 'code')
            ->get()
            ->keyBy('id');

        $rows = [];
        foreach ($grouped as $enrollmentId => $subjectRows) {
            $subjects = [];
            $resultStatus = 'pass';
            $remarks = null;

            foreach ($subjectRows as $subjectRow) {
                $subjectId = (int) $subjectRow->subject_id;
                $isAbsent = $subjectRow->marks_obtained === null;
                $obtained = $isAbsent ? 0.0 : (float) $subjectRow->marks_obtained;
                $max = (float) $subjectRow->max_marks;
                $passMarks = $passMarksBySubject->has($subjectId)
                    ? (float) ($passMarksBySubject->get($subjectId) ?? 0.0)
                    : 0.0;

                if ($isAbsent || $obtained < $passMarks) {
                    $resultStatus = 'fail';
                }

                $subjectPercentage = $max > 0 ? round(($obtained / $max) * 100, 2) : 0.0;

                $subjects[] = [
                    'subject_id' => $subjectId,
                    'obtained_marks' => $obtained,
                    'max_marks' => $max,
                    'passing_marks' => $passMarks,
                    'subject_name_snapshot' => $subjectSnapshotMap->get($subjectId)->name ?? null,
                    'subject_code_snapshot' => $subjectSnapshotMap->get($subjectId)->subject_code ?: ($subjectSnapshotMap->get($subjectId)->code ?? null),
                    'grade' => $isAbsent ? 'ABS' : $this->resolveGrade($subjectPercentage),
                    'is_absent' => $isAbsent,
                    'teacher_id' => null,
                ];

                if (!$remarks && !empty($subjectRow->remarks)) {
                    $remarks = (string) $subjectRow->remarks;
                }
            }

            $rows[] = [
                'enrollment_id' => (int) $enrollmentId,
                'result_status' => $resultStatus,
                'remarks' => $remarks,
                'grade' => null,
                'subjects' => $subjects,
            ];
        }

        $publishedRows = $this->persistPublishedRows(
            $request,
            $session,
            $userId,
            $rows,
            $validated['reason'] ?? null
        );

        return response()->json([
            'message' => 'Class-wise result published successfully.',
            'exam_session_id' => $session->id,
            'class_id' => (int) $session->class_id,
            'published_count' => count($publishedRows),
            'rows' => $publishedRows,
        ]);
    }

    public function lockSession(Request $request, int $sessionId)
    {
        $userId = $this->requireSuperAdmin($request);

        $session = ExamSession::query()->findOrFail($sessionId);
        if ($session->status === 'locked') {
            return response()->json(['message' => 'Exam session already locked.']);
        }

        if ($session->status !== 'published') {
            return response()->json(['message' => 'Only published sessions can be locked.'], 422);
        }

        $session->status = 'locked';
        $session->locked_at = now();
        $session->save();

        ResultAuditLog::query()->create([
            'user_id' => $userId,
            'action' => 'lock',
            'reason' => $request->input('reason'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => (string) $request->header('X-Request-ID', Str::uuid()->toString()),
            'metadata' => ['exam_session_id' => $session->id],
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Exam session locked successfully.']);
    }

    public function unlockSession(Request $request, int $sessionId)
    {
        $userId = $this->requireSuperAdmin($request);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $session = ExamSession::query()->findOrFail($sessionId);
        if ($session->status !== 'locked') {
            return response()->json(['message' => 'Exam session is not locked.'], 422);
        }

        $session->status = 'published';
        $session->locked_at = null;
        $session->save();

        ResultAuditLog::query()->create([
            'user_id' => $userId,
            'action' => 'unlock',
            'reason' => $validated['reason'],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => (string) $request->header('X-Request-ID', Str::uuid()->toString()),
            'metadata' => ['exam_session_id' => $session->id],
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Exam session unlocked successfully.']);
    }

    public function setVisibility(Request $request, int $studentResultId)
    {
        $userId = $this->requireSuperAdmin($request);

        $validated = $request->validate([
            'visibility_status' => ['required', 'in:visible,withheld,under_review,disciplinary_hold'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $studentResult = StudentResult::query()->findOrFail($studentResultId);
        $latestVersion = ResultVisibilityControl::query()
            ->where('student_result_id', $studentResultId)
            ->max('visibility_version');
        $nextVersion = ($latestVersion ?? 0) + 1;
        $status = (string) $validated['visibility_status'];
        $now = now();

        $control = ResultVisibilityControl::query()->create([
            'student_result_id' => $studentResult->id,
            'visibility_status' => $status,
            'blocked_reason' => $validated['reason'] ?? null,
            'blocked_by' => $status === 'visible' ? null : $userId,
            'blocked_at' => $status === 'visible' ? null : $now,
            'unblocked_by' => $status === 'visible' ? $userId : null,
            'unblocked_at' => $status === 'visible' ? $now : null,
            'visibility_version' => $nextVersion,
        ]);

        VisibilityAuditLog::query()->create([
            'user_id' => $userId,
            'student_result_id' => $studentResult->id,
            'action' => $status === 'visible' ? 'unblocked' : 'blocked',
            'reason' => $validated['reason'] ?? null,
            'ip_address' => $request->ip(),
            'created_at' => $now,
        ]);

        return response()->json([
            'message' => 'Result visibility updated.',
            'visibility' => $control,
        ]);
    }

    public function revokeVerification(Request $request, int $studentResultId)
    {
        $userId = $this->requireSuperAdmin($request);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $result = StudentResult::query()->findOrFail($studentResultId);
        if ($result->verification_status === 'revoked') {
            return response()->json(['message' => 'Verification is already revoked.']);
        }

        $result->verification_status = 'revoked';
        $result->save();

        ResultAuditLog::query()->create([
            'user_id' => $userId,
            'student_result_id' => $result->id,
            'action' => 'revoke_verification',
            'old_version' => $result->version,
            'new_version' => $result->version,
            'reason' => $validated['reason'],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => (string) $request->header('X-Request-ID', Str::uuid()->toString()),
            'metadata' => ['exam_session_id' => $result->exam_session_id],
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Verification revoked successfully.']);
    }

    public function publishedResults(Request $request)
    {
        $viewer = $this->requireResultViewer($request);
        $teacherId = $viewer['teacher_id'];
        $accessibleStudentIds = $viewer['student_ids'] ?? [];

        $query = StudentResult::query()
            ->with([
                'examSession:id,name,class_id,academic_year_id,published_at,class_name_snapshot,exam_name_snapshot,academic_year_label_snapshot,school_snapshot',
                'examSession.classModel:id,name',
                'examSession.academicYear:id,name',
                'enrollment:id,student_id,roll_number',
                'student:id,user_id,admission_number,city,state',
                'student.user:id,first_name,last_name',
                'latestVisibility',
            ])
            ->where('is_superseded', false)
            ->whereHas('examSession', function ($q) {
                $q->whereIn('status', ['published', 'locked']);
            })
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        if ($teacherId) {
            $query->whereExists(function ($existsQuery) use ($teacherId) {
                $existsQuery->select(DB::raw(1))
                    ->from('result_marks_snapshots as rms')
                    ->join('enrollments as enr', 'enr.id', '=', 'rms.enrollment_id')
                    ->join('teacher_subject_assignments as tsa', function ($join) use ($teacherId) {
                        $join->on('tsa.subject_id', '=', 'rms.subject_id')
                            ->on('tsa.section_id', '=', 'enr.section_id')
                            ->on('tsa.academic_year_id', '=', 'enr.academic_year_id')
                            ->where('tsa.teacher_id', $teacherId);
                    })
                    ->whereColumn('rms.student_result_id', 'student_results.id');
            });
        }

        if (!empty($accessibleStudentIds)) {
            $query->whereIn('student_id', $accessibleStudentIds);
        }

        $hiddenResultNotice = null;
        if (!$viewer['can_view_hidden']) {
            $hiddenResultsExist = (clone $query)
                ->whereHas('latestVisibility', function ($visibilityQuery) {
                    $visibilityQuery->where('visibility_status', '!=', 'visible');
                });

            if ($request->filled('exam_session_id')) {
                $hiddenResultsExist->where('exam_session_id', (int) $request->input('exam_session_id'));
            }

            if ($request->filled('class_id')) {
                $classId = (int) $request->input('class_id');
                $hiddenResultsExist->whereHas('examSession', fn ($q) => $q->where('class_id', $classId));
            }

            if ($request->filled('search')) {
                $search = trim((string) $request->input('search'));
                $hiddenResultsExist->where(function ($q) use ($search) {
                    $q->whereHas('student', function ($studentQuery) use ($search) {
                        $studentQuery->where('admission_number', 'like', "%{$search}%")
                            ->orWhereHas('user', function ($userQuery) use ($search) {
                                $userQuery->where('first_name', 'like', "%{$search}%")
                                    ->orWhere('last_name', 'like', "%{$search}%");
                            });
                    })->orWhereHas('enrollment', function ($enrollmentQuery) use ($search) {
                        $enrollmentQuery->where('roll_number', 'like', "%{$search}%");
                    });
                });
            }

            if ($hiddenResultsExist->exists()) {
                $hiddenResultNotice = self::HIDDEN_RESULT_MESSAGE;
            }

            $query->whereDoesntHave('latestVisibility', function ($visibilityQuery) {
                $visibilityQuery->where('visibility_status', '!=', 'visible');
            });
        }

        if ($request->filled('exam_session_id')) {
            $query->where('exam_session_id', (int) $request->input('exam_session_id'));
        }

        if ($request->filled('class_id')) {
            $classId = (int) $request->input('class_id');
            $query->whereHas('examSession', fn($q) => $q->where('class_id', $classId));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->whereHas('student', function ($studentQuery) use ($search) {
                    $studentQuery->where('admission_number', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                        });
                })->orWhereHas('enrollment', function ($enrollmentQuery) use ($search) {
                    $enrollmentQuery->where('roll_number', 'like', "%{$search}%");
                });
            });
        }

        $paginator = $query->paginate((int) $request->input('per_page', 20));
        $collection = $paginator->getCollection()->map(function (StudentResult $result) {
            $studentName = trim(($result->student?->user?->first_name ?? '') . ' ' . ($result->student?->user?->last_name ?? ''));
            return [
                'id' => $result->id,
                'serial_number' => $result->id,
                'student_name' => $studentName,
                'enrollment_number' => $result->enrollment?->roll_number,
                'registration_number' => $result->student?->admission_number,
                'exam_name' => $result->examSession?->name,
                'class_name' => $result->examSession?->classModel?->name,
                'academic_year' => $result->examSession?->academicYear?->name,
                'percentage' => (float) $result->percentage,
                'grade' => $result->grade,
                'result_status' => $result->result_status,
                'version' => (int) $result->version,
                'published_at' => $result->published_at?->toDateTimeString(),
                'visibility_status' => $result->latestVisibility?->visibility_status ?? 'visible',
            ];
        });
        $paginator->setCollection($collection);

        $payload = $paginator->toArray();
        $payload['hidden_result_notice'] = $hiddenResultNotice;

        return response()->json($payload);
    }

    public function publishedSessionOptions(Request $request)
    {
        $viewer = $this->requireResultViewer($request);
        $teacherId = $viewer['teacher_id'];
        $accessibleStudentIds = $viewer['student_ids'] ?? [];

        $query = ExamSession::query()
            ->with(['classModel:id,name', 'academicYear:id,name'])
            ->withCount([
                'studentResults as published_results_count' => function ($q) {
                    $q->where('is_superseded', false);
                }
            ])
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        if ($teacherId) {
            $query->whereExists(function ($existsQuery) use ($teacherId) {
                $existsQuery->select(DB::raw(1))
                    ->from('student_results as sr')
                    ->join('result_marks_snapshots as rms', 'rms.student_result_id', '=', 'sr.id')
                    ->join('enrollments as enr', 'enr.id', '=', 'sr.enrollment_id')
                    ->join('teacher_subject_assignments as tsa', function ($join) use ($teacherId) {
                        $join->on('tsa.subject_id', '=', 'rms.subject_id')
                            ->on('tsa.section_id', '=', 'enr.section_id')
                            ->on('tsa.academic_year_id', '=', 'enr.academic_year_id')
                            ->where('tsa.teacher_id', $teacherId);
                    })
                    ->where('sr.is_superseded', false)
                    ->whereColumn('sr.exam_session_id', 'exam_sessions.id');
            });
        }

        if (!empty($accessibleStudentIds)) {
            $query->whereExists(function ($existsQuery) use ($accessibleStudentIds) {
                $existsQuery->select(DB::raw(1))
                    ->from('student_results as sr')
                    ->where('sr.is_superseded', false)
                    ->whereIn('sr.student_id', $accessibleStudentIds)
                    ->whereColumn('sr.exam_session_id', 'exam_sessions.id');
            });
        }

        if ($request->filled('class_id')) {
            $query->where('class_id', (int) $request->input('class_id'));
        }

        if ($request->filled('academic_year_id')) {
            $query->where('academic_year_id', (int) $request->input('academic_year_id'));
        }

        // Session options are driven by finalized marks linked to the session.
        $query->whereExists(function ($existsQuery) {
            $existsQuery->select(DB::raw(1))
                ->from('compiled_marks as cm')
                ->whereColumn('cm.exam_session_id', 'exam_sessions.id')
                ->where('cm.is_finalized', true);
        });

        $sessions = $query->get();
        $sessionIds = $sessions->pluck('id')->map(fn($id) => (int) $id)->all();

        $compiledStats = collect();
        if (!empty($sessionIds)) {
            $compiledStats = DB::table('compiled_marks as cm')
                ->select(
                    'cm.exam_session_id',
                    DB::raw('COUNT(*) as finalized_compiled_rows'),
                    DB::raw('MAX(cm.marked_on) as latest_marked_on')
                )
                ->whereIn('cm.exam_session_id', $sessionIds)
                ->where('cm.is_finalized', true)
                ->groupBy('cm.exam_session_id')
                ->get()
                ->keyBy('exam_session_id');
        }

        $items = $sessions->map(function (ExamSession $session) use ($compiledStats) {
            $stats = $compiledStats->get((int) $session->id);

            return [
                'id' => $session->id,
                'name' => $session->name,
                'class_id' => $session->class_id,
                'class_name' => $session->classModel?->name,
                'academic_year_id' => $session->academic_year_id,
                'academic_year_name' => $session->academicYear?->name,
                'status' => $session->status,
                'published_results_count' => (int) ($session->published_results_count ?? 0),
                'finalized_compiled_rows' => (int) ($stats->finalized_compiled_rows ?? 0),
                'latest_marked_on' => $stats->latest_marked_on ?? null,
            ];
        })->values();

        return response()->json([
            'data' => $items,
        ]);
    }

    public function resultPaper(Request $request, int $studentResultId)
    {
        $viewer = $this->requireResultViewer($request);
        $teacherId = $viewer['teacher_id'];
        $studentId = $viewer['student_id'];
        $accessibleStudentIds = $viewer['student_ids'] ?? [];

        $result = StudentResult::query()
            ->with([
                'examSession:id,name,class_id,academic_year_id,published_at,class_name_snapshot,exam_name_snapshot,academic_year_label_snapshot,school_snapshot',
                'examSession.classModel:id,name',
                'examSession.academicYear:id,name',
                'enrollment:id,student_id,roll_number',
                'student:id,user_id,admission_number,address,city,state,pincode,avatar_url',
                'student.user:id,first_name,last_name,avatar',
                'student.profile:id,student_id,avatar_url',
                'student.parents.user:id,first_name,last_name',
                'snapshots:id,student_result_id,subject_id,obtained_marks,max_marks,passing_marks,subject_name_snapshot,subject_code_snapshot,grade',
                'snapshots.subject:id,name,subject_code,code',
                'latestVisibility:result_visibility_controls.id,result_visibility_controls.student_result_id,result_visibility_controls.visibility_status,result_visibility_controls.visibility_version',
            ])
            ->where('is_superseded', false)
            ->findOrFail($studentResultId);

        if ($teacherId) {
            $isAllocatedToTeacher = DB::table('result_marks_snapshots as rms')
                ->join('enrollments as enr', 'enr.id', '=', 'rms.enrollment_id')
                ->join('teacher_subject_assignments as tsa', function ($join) use ($teacherId) {
                    $join->on('tsa.subject_id', '=', 'rms.subject_id')
                        ->on('tsa.section_id', '=', 'enr.section_id')
                        ->on('tsa.academic_year_id', '=', 'enr.academic_year_id')
                        ->where('tsa.teacher_id', $teacherId);
                })
                ->where('rms.student_result_id', (int) $result->id)
                ->exists();

            if (!$isAllocatedToTeacher) {
                abort(403, 'This result does not belong to your allocated classes.');
            }
        }

        if (!empty($accessibleStudentIds) && !in_array((int) $result->student_id, $accessibleStudentIds, true)) {
            abort(403, 'You can only view allowed student results.');
        }

        $visibilityStatus = $result->latestVisibility?->visibility_status ?? 'visible';
        if (!$viewer['can_view_hidden'] && $visibilityStatus !== 'visible') {
            abort(403, self::HIDDEN_RESULT_MESSAGE);
        }

        $studentName = trim(($result->student?->user?->first_name ?? '') . ' ' . ($result->student?->user?->last_name ?? ''));
        $primaryParent = $result->student?->parents
                ?->sortByDesc(fn($parent) => (int) ($parent->pivot?->is_primary ?? 0))
            ->first();
        $parentName = $primaryParent
            ? trim(($primaryParent->user?->first_name ?? '') . ' ' . ($primaryParent->user?->last_name ?? ''))
            : null;

        $fullAddress = trim(implode(', ', array_filter([
            $result->student?->address,
            $result->student?->city,
            $result->student?->state,
            $result->student?->pincode,
        ])));

        $verificationUrl = $this->buildVerificationUrl(
            (string) $result->verification_uuid,
            (string) $result->verification_hash
        );
        $totalPassingMarks = 0.0;
        $subjects = $result->snapshots
            ->sortBy(fn(ResultMarkSnapshot $snapshot) => $snapshot->subject_name_snapshot ?: ($snapshot->subject?->name ?? ''))
            ->values()
            ->map(function (ResultMarkSnapshot $snapshot) use (&$totalPassingMarks) {
                $passMarks = (float) ($snapshot->passing_marks ?? 0);
                $totalPassingMarks += $passMarks;
                $isAbsent = in_array(strtoupper((string) $snapshot->grade), ['A', 'ABS'], true)
                    && (float) $snapshot->obtained_marks === 0.0;
                $grade = $snapshot->grade;

                if (($grade === null || $grade === '') && !$isAbsent) {
                    $maxMarks = (float) $snapshot->max_marks;
                    $subjectPercentage = $maxMarks > 0
                        ? round(((float) $snapshot->obtained_marks / $maxMarks) * 100, 2)
                        : 0.0;
                    $grade = $this->resolveGrade($subjectPercentage);
                }

                return [
                    'subject_id' => $snapshot->subject_id,
                    'subject_name' => $snapshot->subject_name_snapshot ?: $snapshot->subject?->name,
                    'subject_code' => $snapshot->subject_code_snapshot ?: ($snapshot->subject?->subject_code ?: $snapshot->subject?->code),
                    'is_absent' => $isAbsent,
                    'obtained_marks' => (float) $snapshot->obtained_marks,
                    'passing_marks' => $passMarks,
                    'max_marks' => (float) $snapshot->max_marks,
                    'grade' => $grade,
                ];
            });
        $resolvedRank = $result->rank ?? $this->resolvePublishedRank($result);




        return response()->json([
            // School branding/details are sent from backend so the PDF can stay
            // config-driven and not hardcode anything in the frontend.
            'school' => $this->schoolPayload($result->examSession),
            'result_paper' => [
                'serial_number' => $result->id,
                'student_result_id' => $result->id,
                'student_name' => $studentName,
                'parents_name' => $parentName,
                'address' => $fullAddress,

                //                 'photo_url' => $studentPhotoUrl = $this->resolveResultPhotoUrl([
//     $result->student?->profile?->avatar_url,
//     $result->student?->avatar_url,
//     $result->student?->user?->avatar,
// ]),
// 'photo_data_url' => $this->buildResultImageDataUrl(
//     $result->student?->profile?->avatar_url
//     ?: $result->student?->avatar_url
//     ?: $result->student?->user?->avatar
// ),



                'photo_url' => $studentPhotoUrl = $this->resolveResultPhotoUrl([
                    $result->student?->profile?->avatar_url,
                    $result->student?->avatar_url,
                    $result->student?->user?->avatar,
                ]),
                'photo_data_url' => $this->buildResultImageDataUrl(
                    $result->student?->profile?->avatar_url
                    ?: $result->student?->avatar_url
                    ?: $result->student?->user?->avatar
                ),
                'roll_number' => $result->enrollment?->roll_number,
                'enrollment_number' => $result->enrollment?->roll_number,
                'registration_number' => $result->student?->admission_number,
                'class_name' => $result->examSession?->class_name_snapshot ?: $result->examSession?->classModel?->name,
                'exam_name' => $result->examSession?->exam_name_snapshot ?: $result->examSession?->name,
                'academic_year' => $result->examSession?->academic_year_label_snapshot ?: $result->examSession?->academicYear?->name,
                'published_at' => $result->published_at?->toDateTimeString(),
                'total_marks' => (float) $result->total_marks,
                'total_passing_marks' => $totalPassingMarks,
                'total_max_marks' => (float) $result->total_max_marks,
                'percentage' => (float) $result->percentage,
                'grade' => $result->grade,
                'rank' => $resolvedRank,
                'result_status' => $result->result_status,
                'version' => (int) $result->version,
                'qr_verify_url' => $verificationUrl,
                'subjects' => $subjects,
            ],
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

    private function requireResultViewer(Request $request): array
    {
        $user = $request->user();
        if (!$user) {
            abort(403, 'Authentication required.');
        }

        if ($user->hasRole('super_admin')) {
            return [
                'teacher_id' => null,
                'student_id' => null,
                'student_ids' => [],
                'can_view_hidden' => true,
            ];
        }

        if ($user->hasRole('teacher')) {
            return [
                'teacher_id' => (int) $user->id,
                'student_id' => null,
                'student_ids' => [],
                'can_view_hidden' => false,
            ];
        }

        if ($user->hasRole('student')) {
            $studentId = DB::table('students')
                ->where('user_id', (int) $user->id)
                ->value('id');

            if (!$studentId) {
                abort(403, 'Student profile not found.');
            }

            return [
                'teacher_id' => null,
                'student_id' => (int) $studentId,
                'student_ids' => [(int) $studentId],
                'can_view_hidden' => false,
            ];
        }

        if ($user->hasRole('parent')) {
            $parentId = DB::table('parents')
                ->where('user_id', (int) $user->id)
                ->value('id');

            if (!$parentId) {
                abort(403, 'Parent profile not found.');
            }

            $studentIds = DB::table('student_parent')
                ->where('parent_id', (int) $parentId)
                ->pluck('student_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            if (empty($studentIds)) {
                abort(403, 'No linked students found for this parent account.');
            }

            return [
                'teacher_id' => null,
                'student_id' => null,
                'student_ids' => $studentIds,
                'can_view_hidden' => false,
            ];
        }

        abort(403, 'Only super admin, teacher, student, or parent can view results.');
    }

    private function resolveGrade(float $percentage): ?string
    {
        $scheme = GradingScheme::query()
            ->where('is_active', true)
            ->where('min_percentage', '<=', $percentage)
            ->where('max_percentage', '>=', $percentage)
            ->orderByDesc('max_percentage')
            ->first();

        return $scheme?->grade;
    }

    private function resolvePublishedRank(StudentResult $result): int
    {
        $higherResults = StudentResult::query()
            ->where('exam_session_id', $result->exam_session_id)
            ->where('is_superseded', false)
            ->where(function ($query) use ($result) {
                $query->where('percentage', '>', $result->percentage)
                    ->orWhere(function ($tieQuery) use ($result) {
                        $tieQuery->where('percentage', $result->percentage)
                            ->where('total_marks', '>', $result->total_marks);
                    });
            })
            ->count();

        return $higherResults + 1;
    }

    private function buildVerificationUrl(string $verificationUuid, string $verificationHash): string
    {
        $shortSignature = substr($verificationHash, 0, 16);
        $base = rtrim((string) config('app.url'), '/');

        return "{$base}/api/v1/public/results/verify?v={$verificationUuid}&sig={$shortSignature}";
    }

    private function persistPublishedRows(
        Request $request,
        ExamSession $session,
        int $userId,
        array $rows,
        ?string $reason = null
    ): array {
        $enrollmentIds = collect($rows)->pluck('enrollment_id')->map(fn($id) => (int) $id)->values()->all();
        $subjectIds = collect($rows)
            ->flatMap(fn(array $row) => collect($row['subjects'] ?? [])->pluck('subject_id'))
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $enrollmentMap = DB::table('enrollments')
            ->whereIn('id', $enrollmentIds)
            ->select('id', 'student_id', 'class_id', 'academic_year_id')
            ->get()
            ->keyBy('id');
        $subjectMap = DB::table('subjects')
            ->whereIn('id', $subjectIds)
            ->select('id', 'name', 'subject_code', 'code')
            ->get()
            ->keyBy('id');
        $passMarksBySubject = DB::table('class_subjects')
            ->where('class_id', (int) $session->class_id)
            ->where('academic_year_id', (int) $session->academic_year_id)
            ->pluck('pass_marks', 'subject_id');

        foreach ($enrollmentIds as $enrollmentId) {
            $enrollment = $enrollmentMap->get($enrollmentId);
            if (!$enrollment) {
                throw ValidationException::withMessages([
                    'rows' => ["Enrollment {$enrollmentId} not found."],
                ]);
            }
            if ((int) $enrollment->class_id !== (int) $session->class_id || (int) $enrollment->academic_year_id !== (int) $session->academic_year_id) {
                throw ValidationException::withMessages([
                    'rows' => ["Enrollment {$enrollmentId} does not belong to this class/session context."],
                ]);
            }
        }

        return DB::transaction(function () use ($request, $session, $userId, $rows, $reason, $enrollmentMap, $subjectMap, $passMarksBySubject) {
            $published = [];
            $now = now();
            $this->ensureSessionIdentityLocked($session);

            foreach ($rows as $row) {
                $enrollmentId = (int) $row['enrollment_id'];
                $studentId = (int) $enrollmentMap->get($enrollmentId)->student_id;

                $latest = StudentResult::query()
                    ->where('exam_session_id', $session->id)
                    ->where('enrollment_id', $enrollmentId)
                    ->orderByDesc('version')
                    ->first();

                $oldVersion = $latest?->version;
                $newVersion = ($latest?->version ?? 0) + 1;

                if ($latest) {
                    $latest->is_superseded = true;
                    $latest->save();
                }

                $totalMarks = 0.0;
                $totalMaxMarks = 0.0;

                foreach ($row['subjects'] as $subject) {
                    $obtained = (float) $subject['obtained_marks'];
                    $max = (float) $subject['max_marks'];
                    if ($obtained > $max) {
                        throw ValidationException::withMessages([
                            'rows' => ["Enrollment {$enrollmentId}: obtained marks cannot exceed max marks."],
                        ]);
                    }

                    $totalMarks += $obtained;
                    $totalMaxMarks += $max;
                }

                $percentage = $totalMaxMarks > 0 ? round(($totalMarks / $totalMaxMarks) * 100, 2) : 0.0;
                $resolvedGrade = $row['grade'] ?? $this->resolveGrade($percentage);
                $verificationUuid = (string) Str::uuid();
                $verificationHash = hash_hmac('sha256', $verificationUuid, config('app.key'));

                $studentResult = StudentResult::query()->create([
                    'exam_session_id' => $session->id,
                    'enrollment_id' => $enrollmentId,
                    'student_id' => $studentId,
                    'total_marks' => $totalMarks,
                    'total_max_marks' => $totalMaxMarks,
                    'percentage' => $percentage,
                    'grade' => $resolvedGrade,
                    'rank' => null,
                    'result_status' => $row['result_status'] ?? 'pass',
                    'remarks' => $row['remarks'] ?? null,
                    'version' => $newVersion,
                    'is_superseded' => false,
                    'published_by' => $userId,
                    'published_at' => $now,
                    'verification_uuid' => $verificationUuid,
                    'verification_hash' => $verificationHash,
                    'verification_status' => 'active',
                ]);

                foreach ($row['subjects'] as $subject) {
                    $isAbsent = (bool) ($subject['is_absent'] ?? false);
                    $subjectId = (int) $subject['subject_id'];
                    $subjectMeta = $subjectMap->get($subjectId);
                    $subjectGrade = $subject['grade'] ?? null;
                    if ($isAbsent && ($subjectGrade === null || $subjectGrade === '')) {
                        $subjectGrade = 'ABS';
                    } elseif ($subjectGrade === null || $subjectGrade === '') {
                        $subjectPercentage = (float) $subject['max_marks'] > 0
                            ? round(((float) $subject['obtained_marks'] / (float) $subject['max_marks']) * 100, 2)
                            : 0.0;
                        $subjectGrade = $this->resolveGrade($subjectPercentage);
                    }

                    ResultMarkSnapshot::query()->create([
                        'exam_session_id' => $session->id,
                        'student_result_id' => $studentResult->id,
                        'enrollment_id' => $enrollmentId,
                        'student_id' => $studentId,
                        'subject_id' => $subjectId,
                        'obtained_marks' => (float) $subject['obtained_marks'],
                        'max_marks' => (float) $subject['max_marks'],
                        'passing_marks' => isset($subject['passing_marks'])
                            ? (float) $subject['passing_marks']
                            : (float) ($passMarksBySubject->get($subjectId) ?? 0),
                        'subject_name_snapshot' => $subject['subject_name_snapshot'] ?? ($subjectMeta->name ?? null),
                        'subject_code_snapshot' => $subject['subject_code_snapshot'] ?? (($subjectMeta->subject_code ?? null) ?: ($subjectMeta->code ?? null)),
                        'grade' => $subjectGrade,
                        'teacher_id' => isset($subject['teacher_id']) ? (int) $subject['teacher_id'] : null,
                        'snapshot_version' => $newVersion,
                        'created_at' => $now,
                    ]);
                }

                ResultAuditLog::query()->create([
                    'user_id' => $userId,
                    'student_result_id' => $studentResult->id,
                    'action' => $oldVersion ? 'revise' : 'publish',
                    'old_version' => $oldVersion,
                    'new_version' => $newVersion,
                    'reason' => $reason,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'request_id' => (string) $request->header('X-Request-ID', Str::uuid()->toString()),
                    'metadata' => [
                        'exam_session_id' => $session->id,
                        'enrollment_id' => $enrollmentId,
                    ],
                    'created_at' => $now,
                ]);

                $published[] = [
                    'student_result_id' => $studentResult->id,
                    'enrollment_id' => $enrollmentId,
                    'version' => $newVersion,
                    'verification_uuid' => $verificationUuid,
                    'qr_verify_url' => $this->buildVerificationUrl($verificationUuid, $verificationHash),
                ];
            }

            if ($session->status !== 'published') {
                $session->status = 'published';
                $session->published_at = $now;
                $session->save();
            }

            DB::afterCommit(function () use ($published) {
                $resultIds = collect($published)->pluck('student_result_id')->map(fn($id) => (int) $id)->all();
                app(EventNotificationService::class)->notifyResultPublishedByIds($resultIds);
                app(InAppNotificationService::class)->notifyResultPublishedByIds($resultIds);
            });

            return $published;
        });
    }


    private function schoolPayload(?ExamSession $session = null): array
    {
        $school = is_array($session?->school_snapshot) ? $session->school_snapshot : $this->buildSchoolSnapshot();
        $schoolName = (string) ($school['name'] ?? config('school.name'));
        $schoolLogo = $school['logo_url'] ?? config('school.logo_url');
        $watermarkLogo = $school['watermark_logo_url'] ?? null;
        $normalizedLogoUrl = $this->normalizeResultAssetUrl($schoolLogo);
        $normalizedWatermarkLogoUrl = $this->normalizeResultAssetUrl($watermarkLogo ?: $schoolLogo);

        return [
            'name' => $schoolName,
            // Normalize stored paths like `school/logo/x.png` into browser-safe URLs.
            'logo_url' => $normalizedLogoUrl,
            'logo_data_url' => $this->buildResultImageDataUrl($schoolLogo),
            'address' => $school['address'] ?? config('school.address'),
            'phone' => $school['phone'] ?? config('school.phone'),
            'mobile_number_1' => $school['mobile_number_1'] ?? null,
            'mobile_number_2' => $school['mobile_number_2'] ?? null,
            'website' => $school['website'] ?? config('school.website'),
            'registration_number' => $school['registration_number'] ?? config('school.reg_no'),
            'udise_code' => $school['udise_code'] ?? config('school.udise'),
            'watermark_text' => $school['watermark_text'] ?? $schoolName,
            'watermark_logo_url' => $normalizedWatermarkLogoUrl,
            'watermark_logo_data_url' => $this->buildResultImageDataUrl($watermarkLogo ?: $schoolLogo),
        ];
    }

    private function buildSchoolSnapshot(): array
    {
        $name = SchoolSetting::getValue('school_name', config('school.name'));

        return [
            'name' => $name,
            'address' => SchoolSetting::getValue('school_address', config('school.address')),
            'phone' => SchoolSetting::getValue('school_phone', config('school.phone')),
            'mobile_number_1' => SchoolSetting::getValue('school_mobile_number_1'),
            'mobile_number_2' => SchoolSetting::getValue('school_mobile_number_2'),
            'website' => SchoolSetting::getValue('school_website', config('school.website')),
            'registration_number' => SchoolSetting::getValue('school_registration_number', config('school.reg_no')),
            'udise_code' => SchoolSetting::getValue('school_udise_code', config('school.udise')),
            'logo_url' => SchoolSetting::getValue('school_logo_url', config('school.logo_url')),
            'watermark_logo_url' => SchoolSetting::getValue('school_watermark_logo_url'),
            'watermark_text' => SchoolSetting::getValue('school_watermark_text', $name),
        ];
    }

    private function ensureSessionIdentityLocked(ExamSession $session): void
    {
        if ($session->identity_locked_at !== null) {
            return;
        }

        $examName = $session->exam_name_snapshot ?: ($session->examConfiguration?->name ?: $session->name);
        $className = $session->class_name_snapshot ?: (string) DB::table('classes')->where('id', (int) $session->class_id)->value('name');
        $academicYearLabel = $session->academic_year_label_snapshot ?: (string) DB::table('academic_years')->where('id', (int) $session->academic_year_id)->value('name');

        $session->exam_name_snapshot = $examName;
        $session->class_name_snapshot = $className;
        $session->academic_year_label_snapshot = $academicYearLabel;
        $session->school_snapshot = $session->school_snapshot ?: $this->buildSchoolSnapshot();
        $session->identity_locked_at = now();
        $session->save();
    }

    private function buildResultImageDataUrl(?string $value): ?string
    {
        $path = $this->resolveLocalResultImagePath($value);
        if ($path === null || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $mime = mime_content_type($path) ?: null;
        if ($mime === null || !str_starts_with($mime, 'image/')) {
            return null;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        return 'data:' . $mime . ';base64,' . base64_encode($contents);
    }

    private function resolveLocalResultImagePath(?string $value): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^data:/i', $normalized) === 1) {
            return null;
        }

        if (preg_match('/^https?:/i', $normalized) === 1) {
            $parsed = parse_url($normalized);
            $host = strtolower((string) ($parsed['host'] ?? ''));
            $path = (string) ($parsed['path'] ?? '');
            if (!in_array($host, ['127.0.0.1', 'localhost'], true) || $path === '') {
                return null;
            }
            $normalized = ltrim($path, '/');
        }

        $normalized = str_replace('\\', '/', $normalized);
        $normalized = is_string($normalized) ? ltrim($normalized, '/') : '';
        $relativePath = preg_replace('/^(public\/storage\/|storage\/|public\/)/', '', $normalized);
        $relativePath = is_string($relativePath) ? ltrim($relativePath, '/') : $normalized;

        $candidates = [
            file_exists($normalized) ? $normalized : null,
            public_path($normalized),
            public_path('storage/' . $relativePath),
            storage_path('app/public/' . $relativePath),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
    private function resolveResultPhotoUrl(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $resolved = $this->normalizeResultPhotoCandidate($candidate);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    private function normalizeResultPhotoCandidate(mixed $path): ?string
    {
        $normalized = trim((string) $path);
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^data:|^file:/i', $normalized) === 1) {
            return $normalized;
        }

        if (preg_match('/^https?:/i', $normalized) === 1) {
            return $normalized;
        }

        $normalized = str_replace('\\', '/', $normalized);
        $normalized = preg_replace('/^public\/storage\//', '', $normalized);
        $normalized = preg_replace('/^storage\//', '', $normalized);
        $normalized = is_string($normalized) ? ltrim($normalized, '/') : '';

        return $normalized !== '' ? url('storage/' . $normalized) : null;
    }

    private function normalizeResultAssetUrl(mixed $path): ?string
    {
        // Result paper uses the same normalization rules for logo/photo assets.
        return $this->normalizeResultPhotoCandidate($path);
    }
}
