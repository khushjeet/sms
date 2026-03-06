<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamSession;
use App\Models\GradingScheme;
use App\Models\ResultAuditLog;
use App\Models\ResultMarkSnapshot;
use App\Models\ResultVisibilityControl;
use App\Models\StudentResult;
use App\Models\VisibilityAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResultPublishingController extends Controller
{
    private const HIDDEN_RESULT_MESSAGE = 'Your result is not published please contact to the adminstrative/ principal sir';

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
            'name' => ['required', 'string', 'max:150'],
            'status' => ['nullable', 'in:draft,compiling,published,locked'],
        ]);

        $session = ExamSession::query()->create([
            'academic_year_id' => (int) $validated['academic_year_id'],
            'class_id' => (int) $validated['class_id'],
            'name' => trim((string) $validated['name']),
            'status' => $validated['status'] ?? 'draft',
            'created_by' => $userId,
        ]);

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

        if (isset($validated['class_id']) && (int) $validated['class_id'] !== (int) $session->class_id) {
            return response()->json(['message' => 'Selected class does not match exam session class.'], 422);
        }

        $sectionIds = DB::table('sections')
            ->where('class_id', (int) $session->class_id)
            ->where('academic_year_id', (int) $session->academic_year_id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (empty($sectionIds)) {
            return response()->json(['message' => 'No sections found for this class and academic year.'], 422);
        }

        $activeEnrollmentIds = DB::table('enrollments')
            ->where('class_id', (int) $session->class_id)
            ->where('academic_year_id', (int) $session->academic_year_id)
            ->where('status', 'active')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
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

        $invalidRows = $compiled->filter(fn ($row) => $row->max_marks === null || (float) $row->max_marks <= 0);
        if ($invalidRows->isNotEmpty()) {
            return response()->json([
                'message' => 'Some compiled marks rows are incomplete (null max). Complete them before publishing.',
                'invalid_count' => $invalidRows->count(),
            ], 422);
        }

        $grouped = $compiled->groupBy('enrollment_id');
        $missingEnrollmentIds = array_values(array_diff($activeEnrollmentIds, $grouped->keys()->map(fn ($id) => (int) $id)->all()));
        if (!empty($missingEnrollmentIds)) {
            return response()->json([
                'message' => 'Some active students are missing compiled/finalized marks for selected date.',
                'missing_enrollment_ids' => $missingEnrollmentIds,
            ], 422);
        }

        $passMarksBySubject = DB::table('class_subjects')
            ->where('class_id', (int) $session->class_id)
            ->where('academic_year_id', (int) $session->academic_year_id)
            ->pluck('pass_marks', 'subject_id')
            ->map(fn ($marks) => $marks !== null ? (float) $marks : null);

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

                $subjects[] = [
                    'subject_id' => $subjectId,
                    'obtained_marks' => $obtained,
                    'max_marks' => $max,
                    'grade' => $isAbsent ? 'A' : null,
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

        $query = StudentResult::query()
            ->with([
                'examSession:id,name,class_id,academic_year_id,published_at',
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

        if (!$viewer['can_view_hidden']) {
            $query->whereDoesntHave('latestVisibility', function ($visibilityQuery) {
                $visibilityQuery->where('visibility_status', '!=', 'visible');
            });
        }

        if ($request->filled('exam_session_id')) {
            $query->where('exam_session_id', (int) $request->input('exam_session_id'));
        }

        if ($request->filled('class_id')) {
            $classId = (int) $request->input('class_id');
            $query->whereHas('examSession', fn ($q) => $q->where('class_id', $classId));
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

        return response()->json($paginator);
    }

    public function publishedSessionOptions(Request $request)
    {
        $viewer = $this->requireResultViewer($request);
        $teacherId = $viewer['teacher_id'];

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

        $items = $query->get()->map(function (ExamSession $session) {
            $finalizedRows = DB::table('compiled_marks as cm')
                ->where('cm.exam_session_id', (int) $session->id)
                ->where('cm.is_finalized', true);

            return [
                'id' => $session->id,
                'name' => $session->name,
                'class_id' => $session->class_id,
                'class_name' => $session->classModel?->name,
                'academic_year_id' => $session->academic_year_id,
                'academic_year_name' => $session->academicYear?->name,
                'status' => $session->status,
                'published_results_count' => (int) ($session->published_results_count ?? 0),
                'finalized_compiled_rows' => (int) (clone $finalizedRows)->count(),
                'latest_marked_on' => (clone $finalizedRows)->max('cm.marked_on'),
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

        $result = StudentResult::query()
            ->with([
                'examSession:id,name,class_id,academic_year_id,published_at',
                'examSession.classModel:id,name',
                'examSession.academicYear:id,name',
                'enrollment:id,student_id,roll_number',
                'student:id,user_id,admission_number,address,city,state,pincode,avatar_url',
                'student.user:id,first_name,last_name,avatar',
                'student.parents.user:id,first_name,last_name',
                'snapshots:id,student_result_id,subject_id,obtained_marks,max_marks,grade',
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

        $visibilityStatus = $result->latestVisibility?->visibility_status ?? 'visible';
        if (!$viewer['can_view_hidden'] && $visibilityStatus !== 'visible') {
            abort(403, self::HIDDEN_RESULT_MESSAGE);
        }

        $studentName = trim(($result->student?->user?->first_name ?? '') . ' ' . ($result->student?->user?->last_name ?? ''));
        $primaryParent = $result->student?->parents
            ?->sortByDesc(fn ($parent) => (int) ($parent->pivot?->is_primary ?? 0))
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

        return response()->json([
            'school' => [
                'name' => config('school.name'),
                'logo_url' => config('school.logo_url'),
                'address' => config('school.address'),
                'phone' => config('school.phone'),
                'website' => config('school.website'),
            ],
            'result_paper' => [
                'serial_number' => $result->id,
                'student_result_id' => $result->id,
                'student_name' => $studentName,
                'parents_name' => $parentName,
                'address' => $fullAddress,
                'photo_url' => $result->student?->avatar_url ?: $result->student?->user?->avatar,
                'enrollment_number' => $result->enrollment?->roll_number,
                'registration_number' => $result->student?->admission_number,
                'class_name' => $result->examSession?->classModel?->name,
                'exam_name' => $result->examSession?->name,
                'academic_year' => $result->examSession?->academicYear?->name,
                'published_at' => $result->published_at?->toDateTimeString(),
                'total_marks' => (float) $result->total_marks,
                'total_max_marks' => (float) $result->total_max_marks,
                'percentage' => (float) $result->percentage,
                'grade' => $result->grade,
                'rank' => $result->rank,
                'result_status' => $result->result_status,
                'remarks' => $result->remarks,
                'version' => (int) $result->version,
                'qr_verify_url' => $verificationUrl,
                'subjects' => $result->snapshots
                    ->sortBy('subject.name')
                    ->values()
                    ->map(fn (ResultMarkSnapshot $snapshot) => [
                        'subject_id' => $snapshot->subject_id,
                        'subject_name' => $snapshot->subject?->name,
                        'subject_code' => $snapshot->subject?->subject_code ?: $snapshot->subject?->code,
                        'is_absent' => strtoupper((string) $snapshot->grade) === 'A' && (float) $snapshot->obtained_marks === 0.0,
                        'obtained_marks' => (float) $snapshot->obtained_marks,
                        'max_marks' => (float) $snapshot->max_marks,
                        'grade' => $snapshot->grade,
                    ]),
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
                'can_view_hidden' => true,
            ];
        }

        if ($user->hasRole('teacher')) {
            return [
                'teacher_id' => (int) $user->id,
                'can_view_hidden' => false,
            ];
        }

        abort(403, 'Only super admin or teacher can view results.');
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
        $enrollmentIds = collect($rows)->pluck('enrollment_id')->map(fn ($id) => (int) $id)->values()->all();
        $enrollmentMap = DB::table('enrollments')
            ->whereIn('id', $enrollmentIds)
            ->select('id', 'student_id', 'class_id', 'academic_year_id')
            ->get()
            ->keyBy('id');

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

        return DB::transaction(function () use ($request, $session, $userId, $rows, $reason, $enrollmentMap) {
            $published = [];
            $now = now();

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
                    $subjectGrade = $subject['grade'] ?? null;
                    if ($isAbsent && ($subjectGrade === null || $subjectGrade === '')) {
                        $subjectGrade = 'A';
                    }

                    ResultMarkSnapshot::query()->create([
                        'exam_session_id' => $session->id,
                        'student_result_id' => $studentResult->id,
                        'enrollment_id' => $enrollmentId,
                        'student_id' => $studentId,
                        'subject_id' => (int) $subject['subject_id'],
                        'obtained_marks' => (float) $subject['obtained_marks'],
                        'max_marks' => (float) $subject['max_marks'],
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

            return $published;
        });
    }
}
