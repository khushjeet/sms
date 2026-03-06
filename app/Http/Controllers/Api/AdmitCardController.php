<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdmitAuditLog;
use App\Models\AdmitCard;
use App\Models\AdmitScheduleSnapshot;
use App\Models\AdmitVerificationLog;
use App\Models\AdmitVisibilityControl;
use App\Models\AcademicYearExamConfig;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Barryvdh\DomPDF\Facade\Pdf;

class AdmitCardController extends Controller
{
    private const HIDDEN_ADMIT_MESSAGE = 'Admit card is not available currently. Please contact administration.';

    public function sessions(Request $request)
    {
        $userId = $this->requireSuperAdmin($request);

        $classId = $request->filled('class_id') ? (int) $request->input('class_id') : null;
        $academicYearId = $request->filled('academic_year_id') ? (int) $request->input('academic_year_id') : null;
        $examConfigurationId = $request->filled('exam_configuration_id') ? (int) $request->input('exam_configuration_id') : null;

        // Ensure a draft session exists for the selected exam configuration,
        // so Admit Management can auto-select it even before marks are finalized.
        if ($classId && $academicYearId && $examConfigurationId) {
            $examConfig = AcademicYearExamConfig::query()
                ->where('id', $examConfigurationId)
                ->where('academic_year_id', $academicYearId)
                ->first();

            if ($examConfig) {
                $sessionName = trim((string) $examConfig->name);
                if ($sessionName !== '') {
                    $session = ExamSession::query()->firstOrCreate(
                        [
                            'academic_year_id' => $academicYearId,
                            'class_id' => $classId,
                            'exam_configuration_id' => $examConfigurationId,
                        ],
                        [
                            'name' => $sessionName,
                            'status' => 'draft',
                            'created_by' => $userId,
                        ]
                    );

                    if ((string) $session->name !== $sessionName) {
                        $session->name = $sessionName;
                        $session->save();
                    }
                }
            }
        }

        $query = ExamSession::query()
            ->with(['academicYear:id,name', 'classModel:id,name', 'examConfiguration:id,name'])
            ->withCount([
                'admitCards as active_admit_count' => fn ($q) => $q->where('is_superseded', false),
                'admitCards as published_admit_count' => fn ($q) => $q->where('is_superseded', false)->where('status', 'published'),
            ])
            ->orderByDesc('id');

        if ($academicYearId) {
            $query->where('academic_year_id', $academicYearId);
        }
        if ($classId) {
            $query->where('class_id', $classId);
        }
        if ($examConfigurationId) {
            $query->where('exam_configuration_id', $examConfigurationId);
        }
        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        return response()->json($query->paginate((int) $request->input('per_page', 20)));
    }

    public function sessionCards(Request $request, int $sessionId)
    {
        $this->requireSuperAdmin($request);

        $query = AdmitCard::query()
            ->with([
                'student:id,user_id',
                'student.user:id,first_name,last_name',
                'latestVisibility:admit_visibility_controls.id,admit_visibility_controls.admit_card_id,admit_visibility_controls.visibility_status',
            ])
            ->where('exam_session_id', (int) $sessionId)
            ->where('is_superseded', false)
            ->orderBy('seat_number')
            ->orderByDesc('id');

        $perPage = (int) $request->input('per_page', 200);

        return response()->json($query->paginate($perPage)->through(function (AdmitCard $admit) {
            $studentName = trim(($admit->student?->user?->first_name ?? '') . ' ' . ($admit->student?->user?->last_name ?? ''));

            return [
                'id' => (int) $admit->id,
                'student_name' => $studentName !== '' ? $studentName : null,
                'roll_number' => $admit->roll_number,
                'seat_number' => $admit->seat_number,
                'status' => $admit->status,
                'version' => (int) $admit->version,
                'published_at' => $admit->published_at?->toDateTimeString(),
                'visibility_status' => $admit->latestVisibility?->visibility_status ?? 'visible',
            ];
        }));
    }

    public function generate(Request $request)
    {
        $userId = $this->requireSuperAdmin($request);

        $validated = $request->validate(
            [
                'exam_session_id' => ['required', 'integer', 'exists:exam_sessions,id'],
                'reason' => ['nullable', 'string', 'max:1000'],
                'center_name' => ['nullable', 'string', 'max:150'],
                'seat_prefix' => ['nullable', 'string', 'max:15'],
                'schedule.subjects' => ['nullable', 'array', 'max:50'],
                'schedule.subjects.*.subject_id' => ['required_with:schedule.subjects', 'integer', 'exists:subjects,id'],
                'schedule.subjects.*.subject_name' => ['nullable', 'string', 'max:150'],
                'schedule.subjects.*.subject_code' => ['nullable', 'string', 'max:50'],
                'schedule.subjects.*.exam_date' => ['nullable', 'date'],
                'schedule.subjects.*.exam_shift' => ['nullable', 'in:1st Shift,2nd Shift'],
                'schedule.subjects.*.start_time' => ['nullable', 'date_format:H:i'],
                'schedule.subjects.*.end_time' => ['nullable', 'date_format:H:i'],
                'schedule.subjects.*.room_number' => ['nullable', 'string', 'max:50'],
                'schedule.subjects.*.max_marks' => ['nullable', 'numeric', 'min:1', 'max:1000'],
            ],
            [
                'exam_session_id.required' => 'Session is required.',
            ]
        );

        $session = ExamSession::query()
            ->with(['academicYear:id,name', 'classModel:id,name', 'examConfiguration:id,name'])
            ->findOrFail((int) $validated['exam_session_id']);

        if ($session->status === 'locked') {
            return response()->json(['message' => 'Exam session is locked. Admit generation is not allowed.'], 422);
        }

        $activeEnrollments = Enrollment::query()
            ->where('academic_year_id', (int) $session->academic_year_id)
            ->where('class_id', (int) $session->class_id)
            ->where('status', 'active')
            ->orderBy('id')
            ->get(['id', 'student_id', 'roll_number']);

        if ($activeEnrollments->isEmpty()) {
            return response()->json(['message' => 'No active enrollments found for this exam session.'], 422);
        }

        $providedSubjects = $validated['schedule']['subjects'] ?? [];
        $scheduleSubjects = !empty($providedSubjects)
            ? collect($providedSubjects)->map(fn (array $row) => [
                'subject_id' => (int) $row['subject_id'],
                'subject_name' => isset($row['subject_name']) ? trim((string) $row['subject_name']) : null,
                'subject_code' => isset($row['subject_code']) ? trim((string) $row['subject_code']) : null,
                'exam_date' => $row['exam_date'] ?? null,
                'exam_shift' => isset($row['exam_shift']) ? trim((string) $row['exam_shift']) : null,
                'start_time' => $row['start_time'] ?? null,
                'end_time' => $row['end_time'] ?? null,
                'room_number' => isset($row['room_number']) ? trim((string) $row['room_number']) : null,
                'max_marks' => isset($row['max_marks']) ? (float) $row['max_marks'] : null,
            ])->values()->all()
            : $this->defaultScheduleSnapshot((int) $session->class_id, (int) $session->academic_year_id);

        if (empty($scheduleSubjects)) {
            return response()->json(['message' => 'No subjects found to create admit schedule snapshot.'], 422);
        }

        $now = now();
        $seatPrefix = trim((string) ($validated['seat_prefix'] ?? 'S'));
        $centerName = isset($validated['center_name']) ? trim((string) $validated['center_name']) : null;
        $reason = $validated['reason'] ?? null;

        $summary = DB::transaction(function () use (
            $request,
            $session,
            $activeEnrollments,
            $userId,
            $scheduleSubjects,
            $now,
            $seatPrefix,
            $centerName,
            $reason
        ) {
            $nextSnapshotVersion = ((int) AdmitScheduleSnapshot::query()
                ->where('exam_session_id', (int) $session->id)
                ->max('snapshot_version')) + 1;

            $snapshot = AdmitScheduleSnapshot::query()->create([
                'exam_session_id' => (int) $session->id,
                'snapshot_version' => $nextSnapshotVersion,
                'schedule_snapshot' => [
                    'generated_at' => $now->toDateTimeString(),
                    'exam_session' => [
                        'id' => (int) $session->id,
                        'name' => $session->name,
                        'status' => $session->status,
                        'academic_year_id' => (int) $session->academic_year_id,
                        'academic_year_name' => $session->academicYear?->name,
                        'class_id' => (int) $session->class_id,
                        'class_name' => $session->classModel?->name,
                        'exam_configuration_id' => $session->exam_configuration_id,
                        'exam_configuration_name' => $session->examConfiguration?->name,
                    ],
                    'subjects' => $scheduleSubjects,
                ],
                'created_by' => $userId,
                'created_at' => $now,
            ]);

            $generatedCount = 0;
            $regeneratedCount = 0;

            foreach ($activeEnrollments as $index => $enrollment) {
                $latest = AdmitCard::query()
                    ->where('exam_session_id', (int) $session->id)
                    ->where('enrollment_id', (int) $enrollment->id)
                    ->orderByDesc('version')
                    ->first();

                $oldVersion = $latest?->version;
                $newVersion = ($latest?->version ?? 0) + 1;

                if ($latest) {
                    $latest->is_superseded = true;
                    $latest->save();
                    $regeneratedCount++;
                } else {
                    $generatedCount++;
                }

                $verificationUuid = (string) Str::uuid();
                $verificationHash = hash_hmac('sha256', $verificationUuid, config('app.key'));
                $seatNumber = sprintf('%s-%04d', $seatPrefix !== '' ? $seatPrefix : 'S', $index + 1);

                $admitCard = AdmitCard::query()->create([
                    'exam_session_id' => (int) $session->id,
                    'admit_schedule_snapshot_id' => (int) $snapshot->id,
                    'enrollment_id' => (int) $enrollment->id,
                    'student_id' => (int) $enrollment->student_id,
                    'roll_number' => (string) ($enrollment->roll_number ?: "R-{$session->id}-{$enrollment->id}"),
                    'seat_number' => $seatNumber,
                    'center_name' => $centerName,
                    'status' => 'draft',
                    'version' => $newVersion,
                    'is_superseded' => false,
                    'remarks' => null,
                    'generated_by' => $userId,
                    'generated_at' => $now,
                    'published_by' => null,
                    'published_at' => null,
                    'verification_uuid' => $verificationUuid,
                    'verification_hash' => $verificationHash,
                    'verification_status' => 'active',
                ]);

                AdmitVisibilityControl::query()->create([
                    'admit_card_id' => (int) $admitCard->id,
                    'visibility_status' => 'visible',
                    'visibility_version' => 1,
                ]);

                AdmitAuditLog::query()->create([
                    'user_id' => $userId,
                    'exam_session_id' => (int) $session->id,
                    'admit_card_id' => (int) $admitCard->id,
                    'action' => $oldVersion ? 'regenerate' : 'generate',
                    'old_version' => $oldVersion,
                    'new_version' => $newVersion,
                    'reason' => $reason,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'request_id' => (string) $request->header('X-Request-ID', Str::uuid()->toString()),
                    'metadata' => [
                        'enrollment_id' => (int) $enrollment->id,
                        'snapshot_version' => $nextSnapshotVersion,
                    ],
                    'created_at' => $now,
                ]);
            }

            return [
                'snapshot_id' => (int) $snapshot->id,
                'snapshot_version' => $nextSnapshotVersion,
                'total_students' => (int) $activeEnrollments->count(),
                'generated_count' => $generatedCount,
                'regenerated_count' => $regeneratedCount,
            ];
        });

        return response()->json([
            'message' => 'Admit cards generated in draft state.',
            'exam_session_id' => (int) $session->id,
            'summary' => $summary,
        ]);
    }

    public function publishSession(Request $request, int $sessionId)
    {
        $userId = $this->requireSuperAdmin($request);
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $session = ExamSession::query()->findOrFail($sessionId);

        $latestAdmitIds = AdmitCard::query()
            ->where('exam_session_id', (int) $session->id)
            ->where('is_superseded', false)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (empty($latestAdmitIds)) {
            return response()->json(['message' => 'No generated admit cards found for this session.'], 422);
        }

        $eligibleIds = AdmitCard::query()
            ->whereIn('id', $latestAdmitIds)
            ->whereIn('status', ['draft', 'blocked'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (empty($eligibleIds)) {
            return response()->json(['message' => 'No draft admit cards available for publishing.'], 422);
        }

        $snapshotIds = AdmitCard::query()
            ->whereIn('id', $eligibleIds)
            ->pluck('admit_schedule_snapshot_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (empty($snapshotIds)) {
            return response()->json(['message' => 'No admit schedule snapshot found for publishing.'], 422);
        }

        $snapshots = AdmitScheduleSnapshot::query()
            ->whereIn('id', $snapshotIds)
            ->get(['id', 'snapshot_version', 'schedule_snapshot']);

        foreach ($snapshots as $snapshot) {
            $subjects = $snapshot->schedule_snapshot['subjects'] ?? [];
            $incompleteRows = $this->findIncompleteScheduleRows(is_array($subjects) ? $subjects : []);

            if (!empty($incompleteRows)) {
                return response()->json([
                    'message' => 'Exam timetable is incomplete. Set exam date, shift, start time, and end time for all subjects before publishing.',
                    'snapshot_id' => (int) $snapshot->id,
                    'snapshot_version' => (int) $snapshot->snapshot_version,
                    'incomplete_subjects_count' => count($incompleteRows),
                    'incomplete_subjects' => array_slice($incompleteRows, 0, 10),
                ], 422);
            }
        }

        $now = now();
        AdmitCard::query()
            ->whereIn('id', $eligibleIds)
            ->update([
                'status' => 'published',
                'published_by' => $userId,
                'published_at' => $now,
                'updated_at' => $now,
            ]);

        AdmitAuditLog::query()->create([
            'user_id' => $userId,
            'exam_session_id' => (int) $session->id,
            'action' => 'publish',
            'reason' => $validated['reason'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => (string) $request->header('X-Request-ID', Str::uuid()->toString()),
            'metadata' => [
                'published_count' => count($eligibleIds),
            ],
            'created_at' => $now,
        ]);

        return response()->json([
            'message' => 'Admit cards published successfully.',
            'exam_session_id' => (int) $session->id,
            'published_count' => count($eligibleIds),
        ]);
    }

    public function setVisibility(Request $request, int $admitCardId)
    {
        $userId = $this->requireSuperAdmin($request);
        $validated = $request->validate([
            'visibility_status' => ['required', 'in:visible,withheld,under_review,disciplinary_hold'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $admitCard = AdmitCard::query()->findOrFail($admitCardId);
        $latestVersion = AdmitVisibilityControl::query()
            ->where('admit_card_id', (int) $admitCard->id)
            ->max('visibility_version');
        $nextVersion = ($latestVersion ?? 0) + 1;
        $status = (string) $validated['visibility_status'];
        $now = now();

        $control = AdmitVisibilityControl::query()->create([
            'admit_card_id' => (int) $admitCard->id,
            'visibility_status' => $status,
            'blocked_reason' => $validated['reason'] ?? null,
            'blocked_by' => $status === 'visible' ? null : $userId,
            'blocked_at' => $status === 'visible' ? null : $now,
            'unblocked_by' => $status === 'visible' ? $userId : null,
            'unblocked_at' => $status === 'visible' ? $now : null,
            'visibility_version' => $nextVersion,
        ]);

        if ($status === 'visible') {
            if ($admitCard->status === 'blocked') {
                $admitCard->status = $admitCard->published_at ? 'published' : 'draft';
                $admitCard->save();
            }
        } else {
            $admitCard->status = 'blocked';
            $admitCard->save();
        }

        AdmitAuditLog::query()->create([
            'user_id' => $userId,
            'exam_session_id' => (int) $admitCard->exam_session_id,
            'admit_card_id' => (int) $admitCard->id,
            'action' => $status === 'visible' ? 'unblock' : 'block',
            'old_version' => $admitCard->version,
            'new_version' => $admitCard->version,
            'reason' => $validated['reason'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => (string) $request->header('X-Request-ID', Str::uuid()->toString()),
            'metadata' => ['visibility_version' => $nextVersion],
            'created_at' => $now,
        ]);

        return response()->json([
            'message' => 'Admit card visibility updated.',
            'visibility' => $control,
        ]);
    }

    public function myLatest(Request $request)
    {
        $student = $this->resolveStudent($request);
        $academicYearId = $request->filled('academic_year_id') ? (int) $request->input('academic_year_id') : null;

        $query = AdmitCard::query()
            ->with([
                'examSession:id,name,status,class_id,academic_year_id,published_at',
                'examSession.classModel:id,name',
                'examSession.academicYear:id,name',
                'latestVisibility:admit_visibility_controls.id,admit_visibility_controls.admit_card_id,admit_visibility_controls.visibility_status',
            ])
            ->where('student_id', (int) $student->id)
            ->where('is_superseded', false)
            ->orderByDesc('generated_at')
            ->orderByDesc('id');

        if ($academicYearId) {
            $query->whereHas('examSession', fn ($q) => $q->where('academic_year_id', $academicYearId));
        }

        /** @var AdmitCard|null $admit */
        $admit = $query->first();

        if (!$admit) {
            return response()->json([
                'state' => 'not_generated',
                'message' => 'Admit card is not generated yet.',
                'admit_card' => null,
            ]);
        }

        $visibility = $admit->latestVisibility?->visibility_status ?? 'visible';
        if ($visibility !== 'visible' || $admit->status === 'blocked') {
            return response()->json([
                'state' => 'blocked',
                'message' => self::HIDDEN_ADMIT_MESSAGE,
                'admit_card' => [
                    'id' => (int) $admit->id,
                    'status' => $admit->status,
                    'exam_name' => $admit->examSession?->name,
                    'version' => (int) $admit->version,
                    'published_at' => $admit->published_at?->toDateTimeString(),
                    'download_url' => null,
                ],
            ]);
        }

        if ($admit->status === 'draft') {
            return response()->json([
                'state' => 'generated_not_published',
                'message' => 'Admit card is generated and under preparation.',
                'admit_card' => [
                    'id' => (int) $admit->id,
                    'status' => $admit->status,
                    'exam_name' => $admit->examSession?->name,
                    'version' => (int) $admit->version,
                    'published_at' => null,
                    'download_url' => null,
                ],
            ]);
        }

        return response()->json([
            'state' => 'published',
            'message' => 'Admit card is available for download.',
            'admit_card' => [
                'id' => (int) $admit->id,
                'status' => $admit->status,
                'exam_name' => $admit->examSession?->name,
                'version' => (int) $admit->version,
                'published_at' => $admit->published_at?->toDateTimeString(),
                'download_url' => route('admit.cards.paper', ['admitCardId' => (int) $admit->id], false),
            ],
        ]);
    }

    public function bulkPaper(Request $request, int $sessionId)
    {
        $this->requireSuperAdmin($request);

        $session = ExamSession::query()
            ->with(['academicYear:id,name', 'classModel:id,name', 'examConfiguration:id,name'])
            ->findOrFail($sessionId);

        $admits = AdmitCard::query()
            ->with([
                'examSession:id,name,status,class_id,academic_year_id,published_at',
                'examSession.classModel:id,name',
                'examSession.academicYear:id,name',
                'scheduleSnapshot:id,exam_session_id,snapshot_version,schedule_snapshot',
                'enrollment:id,student_id,roll_number,section_id',
                'enrollment.section:id,name',
                'student:id,user_id,admission_number,address,city,state,pincode,avatar_url,date_of_birth',
                'student.user:id,first_name,last_name,avatar',
                'student.profile:id,student_id,father_name,mother_name,avatar_url',
                'student.parents.user:id,first_name,last_name',
                'latestVisibility:admit_visibility_controls.id,admit_visibility_controls.admit_card_id,admit_visibility_controls.visibility_status',
            ])
            ->where('exam_session_id', (int) $sessionId)
            ->where('is_superseded', false)
            ->where('status', 'published')
            ->whereHas('latestVisibility', fn ($q) => $q->where('visibility_status', 'visible'))
            ->orderBy('seat_number')
            ->orderByDesc('id')
            ->get();

        if ($admits->isEmpty()) {
            return response()->json(['message' => 'No admit cards found for this session.'], 422);
        }

        $payload = [
            'school' => [
                'name' => config('school.name'),
                'logo_url' => config('school.logo_url'),
                'address' => config('school.address'),
                'phone' => config('school.phone'),
                'website' => config('school.website'),
                'reg_no' => config('school.reg_no'),
                'udise' => config('school.udise'),
            ],
            'session' => [
                'id' => (int) $session->id,
                'name' => $session->name,
                'status' => $session->status,
                'class_name' => $session->classModel?->name,
                'academic_year' => $session->academicYear?->name,
                'exam_configuration' => $session->examConfiguration?->name,
            ],
            'admitCards' => $admits->map(fn (AdmitCard $admit) => $this->buildAdmitPaperPayload($admit))->values()->all(),
        ];

        $pdf = Pdf::loadView('admits.bulk-admit-cards', $payload)->setPaper('a4', 'portrait');
        $pdf->setOption(['isRemoteEnabled' => true]);
        Storage::disk('local')->makeDirectory('admits');

        $filename = 'admit-session-' . $session->id . '.pdf';
        $path = 'admits/' . $filename;
        Storage::disk('local')->put($path, $pdf->output());

        return Storage::disk('local')->download($path, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function paper(Request $request, int $admitCardId)
    {
        $user = $request->user();
        if (!$user) {
            abort(403, 'Authentication required.');
        }

        $admit = AdmitCard::query()
            ->with([
                'examSession:id,name,status,class_id,academic_year_id,published_at',
                'examSession.classModel:id,name',
                'examSession.academicYear:id,name',
                'scheduleSnapshot:id,exam_session_id,snapshot_version,schedule_snapshot',
                'enrollment:id,student_id,roll_number,section_id',
                'enrollment.section:id,name',
                'student:id,user_id,admission_number,address,city,state,pincode,avatar_url,date_of_birth',
                'student.user:id,first_name,last_name,avatar',
                'student.profile:id,student_id,father_name,mother_name,avatar_url',
                'student.parents.user:id,first_name,last_name',
                'latestVisibility:admit_visibility_controls.id,admit_visibility_controls.admit_card_id,admit_visibility_controls.visibility_status',
            ])
            ->where('is_superseded', false)
            ->findOrFail($admitCardId);

        $isSuperAdmin = $user->hasRole('super_admin');
        $isOwnerStudent = $user->hasRole('student') && $admit->student?->user_id === $user->id;
        if (!$isSuperAdmin && !$isOwnerStudent) {
            abort(403, 'Only super admin or owner student can view admit card.');
        }

        $visibility = $admit->latestVisibility?->visibility_status ?? 'visible';
        if (!$isSuperAdmin && $visibility !== 'visible') {
            abort(403, self::HIDDEN_ADMIT_MESSAGE);
        }

        if (!$isSuperAdmin && $admit->status !== 'published') {
            abort(403, 'Admit card is not published yet.');
        }

        return response()->json([
            'school' => [
                'name' => config('school.name'),
                'logo_url' => config('school.logo_url'),
                'address' => config('school.address'),
                'phone' => config('school.phone'),
                'website' => config('school.website'),
                'reg_no' => config('school.reg_no'),
                'udise' => config('school.udise'),
            ],
            'admit_card' => $this->buildAdmitPaperPayload($admit),
        ]);
    }

    public function paperPdf(Request $request, int $admitCardId)
    {
        $this->requireSuperAdmin($request);

        $admit = AdmitCard::query()
            ->with([
                'examSession:id,name,status,class_id,academic_year_id,published_at',
                'examSession.classModel:id,name',
                'examSession.academicYear:id,name',
                'scheduleSnapshot:id,exam_session_id,snapshot_version,schedule_snapshot',
                'enrollment:id,student_id,roll_number,section_id',
                'enrollment.section:id,name',
                'student:id,user_id,admission_number,address,city,state,pincode,avatar_url,date_of_birth',
                'student.user:id,first_name,last_name,avatar',
                'student.profile:id,student_id,father_name,mother_name,avatar_url',
                'student.parents.user:id,first_name,last_name',
                'latestVisibility:admit_visibility_controls.id,admit_visibility_controls.admit_card_id,admit_visibility_controls.visibility_status',
            ])
            ->where('is_superseded', false)
            ->findOrFail($admitCardId);

        $payload = [
            'school' => [
                'name' => config('school.name'),
                'logo_url' => config('school.logo_url'),
                'address' => config('school.address'),
                'phone' => config('school.phone'),
                'website' => config('school.website'),
                'reg_no' => config('school.reg_no'),
                'udise' => config('school.udise'),
            ],
            'session' => [
                'name' => $admit->examSession?->name,
                'class_name' => $admit->examSession?->classModel?->name,
                'academic_year' => $admit->examSession?->academicYear?->name,
            ],
            'card' => $this->buildAdmitPaperPayload($admit),
        ];

        $pdf = Pdf::loadView('admits.single-admit-card', $payload)->setPaper('a4', 'portrait');
        $pdf->setOption(['isRemoteEnabled' => true]);
        Storage::disk('local')->makeDirectory('admits');

        $filename = 'admit-' . $admit->id . '.pdf';
        $path = 'admits/' . $filename;
        Storage::disk('local')->put($path, $pdf->output());

        return Storage::disk('local')->download($path, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function verifyPublic(Request $request)
    {
        $uuid = trim((string) $request->query('v', ''));
        $signature = trim((string) $request->query('sig', ''));

        if ($uuid === '' || $signature === '') {
            $this->logVerificationAttempt(null, $uuid !== '' ? $uuid : null, 'missing', 'Missing verification parameters.', $request);
            return response()->json(['verified' => false, 'message' => 'This is not our admit record.'], 200);
        }

        $admit = AdmitCard::query()
            ->with([
                'examSession:id,name,class_id,academic_year_id',
                'examSession.classModel:id,name',
                'examSession.academicYear:id,name',
                'student:id,user_id,admission_number',
                'student.user:id,first_name,last_name',
                'latestVisibility:admit_visibility_controls.id,admit_visibility_controls.admit_card_id,admit_visibility_controls.visibility_status',
            ])
            ->where('verification_uuid', $uuid)
            ->where('is_superseded', false)
            ->first();

        if (!$admit) {
            $this->logVerificationAttempt(null, $uuid, 'invalid', 'Verification UUID not found.', $request);
            return response()->json(['verified' => false, 'message' => 'This is not our admit record.'], 200);
        }

        $expected = strtolower(substr((string) $admit->verification_hash, 0, 16));
        if (!hash_equals($expected, strtolower($signature))) {
            $this->logVerificationAttempt((int) $admit->id, $uuid, 'invalid', 'Signature mismatch.', $request);
            return response()->json(['verified' => false, 'message' => 'This is not our admit record.'], 200);
        }

        if ($admit->verification_status === 'revoked' || $admit->status === 'revoked') {
            $this->logVerificationAttempt((int) $admit->id, $uuid, 'revoked', 'Verification is revoked.', $request);
            return response()->json(['verified' => false, 'message' => 'This is not our admit record.'], 200);
        }

        $visibilityStatus = $admit->latestVisibility?->visibility_status ?? 'visible';
        if ($visibilityStatus !== 'visible' || $admit->status === 'blocked') {
            $this->logVerificationAttempt((int) $admit->id, $uuid, 'withheld', self::HIDDEN_ADMIT_MESSAGE, $request);
            return response()->json(['verified' => false, 'message' => self::HIDDEN_ADMIT_MESSAGE], 200);
        }

        $studentName = trim(($admit->student?->user?->first_name ?? '') . ' ' . ($admit->student?->user?->last_name ?? ''));
        $this->logVerificationAttempt((int) $admit->id, $uuid, 'verified', 'Admit card verified successfully.', $request);

        return response()->json([
            'verified' => true,
            'message' => 'Verified admit card.',
            'data' => [
                'student_name' => $studentName,
                'admission_number' => $admit->student?->admission_number,
                'class_name' => $admit->examSession?->classModel?->name,
                'exam_name' => $admit->examSession?->name,
                'academic_year' => $admit->examSession?->academicYear?->name,
                'roll_number' => $admit->roll_number,
                'seat_number' => $admit->seat_number,
                'version' => (int) $admit->version,
                'published_at' => $admit->published_at?->toDateTimeString(),
            ],
        ], 200);
    }

    private function defaultScheduleSnapshot(int $classId, int $academicYearId): array
    {
        return DB::table('class_subjects as cs')
            ->join('subjects as s', 's.id', '=', 'cs.subject_id')
            ->where('cs.class_id', $classId)
            ->where('cs.academic_year_id', $academicYearId)
            ->orderBy('s.name')
            ->get([
                's.id as subject_id',
                's.name as subject_name',
                's.subject_code',
                's.code',
                'cs.max_marks',
            ])
            ->map(fn ($row) => [
                'subject_id' => (int) $row->subject_id,
                'subject_name' => $row->subject_name,
                'subject_code' => $row->subject_code ?: $row->code,
                'exam_date' => null,
                'exam_shift' => null,
                'start_time' => null,
                'end_time' => null,
                'room_number' => null,
                'max_marks' => $row->max_marks !== null ? (float) $row->max_marks : null,
            ])
            ->values()
            ->all();
    }

    private function findIncompleteScheduleRows(array $subjects): array
    {
        $incomplete = [];

        foreach ($subjects as $row) {
            if (!is_array($row)) {
                continue;
            }

            $missingFields = [];
            if (empty($row['exam_date'])) {
                $missingFields[] = 'exam_date';
            }
            if (empty($row['exam_shift'])) {
                $missingFields[] = 'exam_shift';
            }
            if (empty($row['start_time'])) {
                $missingFields[] = 'start_time';
            }
            if (empty($row['end_time'])) {
                $missingFields[] = 'end_time';
            }

            if (empty($missingFields)) {
                continue;
            }

            $incomplete[] = [
                'subject_id' => isset($row['subject_id']) ? (int) $row['subject_id'] : null,
                'subject_name' => isset($row['subject_name']) ? (string) $row['subject_name'] : null,
                'missing_fields' => $missingFields,
            ];
        }

        return $incomplete;
    }

    private function requireSuperAdmin(Request $request): int
    {
        $user = $request->user();
        if (!$user || !$user->hasRole('super_admin')) {
            abort(403, 'Super admin access required.');
        }

        return (int) $user->id;
    }

    private function resolveStudent(Request $request): Student
    {
        $user = $request->user();
        if (!$user || !$user->hasRole('student')) {
            abort(403, 'Only student users can access this endpoint.');
        }

        /** @var Student|null $student */
        $student = $user->student()->first();
        if (!$student) {
            throw ValidationException::withMessages([
                'student' => ['Student profile not found.'],
            ]);
        }

        return $student;
    }

    private function logVerificationAttempt(
        ?int $admitCardId,
        ?string $verificationUuid,
        string $status,
        string $message,
        Request $request
    ): void {
        AdmitVerificationLog::query()->create([
            'admit_card_id' => $admitCardId,
            'verification_uuid' => $verificationUuid,
            'status' => $status,
            'message' => $message,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'verified_at' => now(),
        ]);
    }

    private function buildAdmitPaperPayload(AdmitCard $admit): array
    {
        $studentName = trim(($admit->student?->user?->first_name ?? '') . ' ' . ($admit->student?->user?->last_name ?? ''));
        $parents = $admit->student?->parents;
        $primaryParent = $parents
            ?->sortByDesc(fn ($parent) => (int) ($parent->pivot?->is_primary ?? 0))
            ->first();
        $fatherParent = $parents?->first(fn ($parent) => strtolower((string) ($parent->pivot?->relation ?? '')) === 'father');
        $motherParent = $parents?->first(fn ($parent) => strtolower((string) ($parent->pivot?->relation ?? '')) === 'mother');
        $studentProfile = $admit->student?->profile;
        $parentName = $primaryParent
            ? trim(($primaryParent->user?->first_name ?? '') . ' ' . ($primaryParent->user?->last_name ?? ''))
            : null;
        $fatherName = trim((string) ($studentProfile?->father_name ?? ''));
        if ($fatherName === '') {
            $fatherName = trim(($fatherParent?->user?->first_name ?? '') . ' ' . ($fatherParent?->user?->last_name ?? ''));
        }
        $fatherName = $fatherName !== '' ? $fatherName : null;
        $motherName = trim((string) ($studentProfile?->mother_name ?? ''));
        if ($motherName === '') {
            $motherName = trim(($motherParent?->user?->first_name ?? '') . ' ' . ($motherParent?->user?->last_name ?? ''));
        }
        $motherName = $motherName !== '' ? $motherName : null;
        $dob = $admit->student?->date_of_birth?->format('Y-m-d');

        $fullAddress = trim(implode(', ', array_filter([
            $admit->student?->address,
            $admit->student?->city,
            $admit->student?->state,
            $admit->student?->pincode,
        ])));

        $verificationUuid = (string) $admit->verification_uuid;
        $verificationSignature = strtolower(substr((string) $admit->verification_hash, 0, 16));
        $verificationBase = url('/api/v1/public/admits/verify');
        $verificationUrl = $verificationUuid !== '' && $verificationSignature !== ''
            ? ($verificationBase . '?v=' . urlencode($verificationUuid) . '&sig=' . urlencode($verificationSignature))
            : null;

        return [
            'id' => (int) $admit->id,
            'student_name' => $studentName,
            'parents_name' => $parentName,
            'father_name' => $fatherName,
            'mother_name' => $motherName,
            'dob' => $dob,
            'address' => $fullAddress,
            'photo_url' => $admit->student?->avatar_url ?: $studentProfile?->avatar_url ?: $admit->student?->user?->avatar,
            'enrollment_number' => $admit->enrollment?->roll_number,
            'registration_number' => $admit->student?->admission_number,
            'class_name' => $admit->examSession?->classModel?->name,
            'section_name' => $admit->enrollment?->section?->name,
            'exam_name' => $admit->examSession?->name,
            'academic_year' => $admit->examSession?->academicYear?->name,
            'roll_number' => $admit->roll_number,
            'seat_number' => $admit->seat_number,
            'center_name' => $admit->center_name,
            'status' => $admit->status,
            'version' => (int) $admit->version,
            'published_at' => $admit->published_at?->toDateTimeString(),
            'verification_url' => $verificationUrl,
            'schedule_snapshot_version' => (int) ($admit->scheduleSnapshot?->snapshot_version ?? 1),
            'schedule' => $admit->scheduleSnapshot?->schedule_snapshot['subjects'] ?? [],
        ];
    }
}
