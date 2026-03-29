<?php

namespace App\Services\StudentDashboard;

use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Permission;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StudentDashboardBuilder
{
    public function __construct(
        private readonly AttendanceWidgetService $attendanceWidgetService,
        private readonly ResultWidgetService $resultWidgetService,
        private readonly FeeSummaryService $feeSummaryService,
        private readonly ExamWidgetService $examWidgetService,
        private readonly AdmitCardWidgetService $admitCardWidgetService,
    ) {
    }

    public function build(User $user, ?int $requestedYearId, string $month): array
    {
        $student = $this->resolveStudent($user);
        $yearOptions = $this->academicYearOptions((int) $student->id);
        $selectedYearId = $this->resolveAcademicYearId($student, $requestedYearId, $yearOptions);
        $selectedYear = $selectedYearId ? AcademicYear::query()->find($selectedYearId) : null;

        $selectedEnrollment = Enrollment::query()
            ->with(['classModel', 'section.class', 'academicYear'])
            ->where('student_id', (int) $student->id)
            ->when($selectedYearId, fn ($q) => $q->where('academic_year_id', $selectedYearId))
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('enrollment_date')
            ->orderByDesc('id')
            ->first();

        $enrollmentIdsInYear = Enrollment::query()
            ->where('student_id', (int) $student->id)
            ->when($selectedYearId, fn ($q) => $q->where('academic_year_id', $selectedYearId))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $widgets = $this->widgetRegistry($user);

        $attendance = $widgets['attendance']['enabled']
            ? $this->attendanceWidgetService->build($selectedEnrollment, $month)
            : $this->emptyAttendance($month);

        $result = $widgets['results']['enabled']
            ? $this->resultWidgetService->build((int) $student->id, $selectedYearId)
            : $this->emptyResult();

        $feeSummary = $widgets['fee']['enabled']
            ? $this->feeSummaryService->build($enrollmentIdsInYear)
            : $this->emptyFeeSummary();

        $upcomingExam = $this->examWidgetService->upcoming($selectedEnrollment, $selectedYearId);
        $admitCard = $widgets['admit_card']['enabled']
            ? $this->admitCardWidgetService->build((int) $student->id, $selectedYearId)
            : $this->emptyAdmitCard();
        $timetable = $widgets['timetable']['enabled']
            ? $this->buildTimetable($selectedEnrollment, $selectedYearId)
            : ['source' => 'permission_denied', 'days' => [], 'slots' => [], 'items' => []];
        $academicHistory = $widgets['academic_history']['enabled']
            ? $this->buildAcademicHistory((int) $student->id)
            : ['source' => 'permission_denied', 'items' => []];
        $attendanceHistory = $widgets['attendance_history']['enabled']
            ? $this->buildAttendanceHistory($enrollmentIdsInYear, $selectedYearId)
            : ['source' => 'permission_denied', 'items' => []];

        $this->auditView($student, $selectedYearId, $month, $result);

        return [
            'dashboard' => 'student',
            'generated_at' => now()->toDateTimeString(),
            'scope' => [
                'student_id' => (int) $student->id,
                'academic_year_id' => $selectedYearId,
                'month' => $month,
            ],
            'academic_year_options' => $yearOptions,
            'profile_summary' => [
                'full_name' => $student->user?->full_name,
                'admission_number' => $student->admission_number,
                'roll_number' => $selectedEnrollment?->roll_number,
                'class' => $selectedEnrollment?->classModel?->name ?? $selectedEnrollment?->section?->class?->name,
                'section' => $selectedEnrollment?->section?->name,
                'academic_year' => $selectedYear?->name ?? $selectedEnrollment?->academicYear?->name,
                'profile_photo' => $student->avatar_url ?: $student->user?->avatar,
                'house' => null,
                'blood_group' => $student->blood_group,
            ],
            'quick_stats' => [
                'attendance_percent' => $attendance['monthly_percentage'],
                'pending_fee' => $feeSummary['pending_amount'],
                'upcoming_exam' => $upcomingExam['name'],
                'assignments_due' => 0,
            ],
            'academic_overview' => [
                'current_academic_year' => $selectedYear?->name ?? $selectedEnrollment?->academicYear?->name,
                'current_term' => $upcomingExam['term'],
                'upcoming_exam' => $upcomingExam,
                'academic_status' => $selectedEnrollment?->status ?? $student->status,
            ],
            'attendance_overview' => $attendance,
            'result_section' => $result,
            'fee_summary' => $feeSummary,
            'admit_card' => $admitCard,
            'notice_board' => [
                'source' => 'not_configured',
                'items' => [],
            ],
            'assignments' => [
                'source' => 'not_configured',
                'items' => [],
            ],
            'timetable' => $timetable,
            'academic_history' => $academicHistory,
            'attendance_history' => $attendanceHistory,
            'widgets' => $widgets,
        ];
    }

    public function resolveStudent(User $user): Student
    {
        /** @var Student|null $student */
        $student = $user->student()
            ->with(['user', 'profile', 'currentEnrollment.section.class', 'currentEnrollment.academicYear'])
            ->first();

        if (!$student) {
            abort(404, 'Student profile not found for authenticated user.');
        }

        return $student;
    }

    public function normalizeMonth(string $month): string
    {
        if (preg_match('/^\d{4}\-\d{2}$/', $month) === 1) {
            return $month;
        }

        return now()->format('Y-m');
    }

    private function academicYearOptions(int $studentId): array
    {
        $cacheKey = "student_dashboard:academic_year_options:{$studentId}";

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($studentId) {
            return Enrollment::query()
                ->where('student_id', $studentId)
                ->with('academicYear:id,name,is_current,status')
                ->orderByDesc('academic_year_id')
                ->get()
                ->map(fn (Enrollment $enrollment) => $enrollment->academicYear)
                ->filter()
                ->unique('id')
                ->values()
                ->map(fn (AcademicYear $year) => [
                    'id' => (int) $year->id,
                    'name' => $year->name,
                    'is_current' => (bool) $year->is_current,
                    'status' => $year->status,
                ])
                ->all();
        });
    }

    private function resolveAcademicYearId(Student $student, ?int $requestedYearId, array $options): ?int
    {
        $optionIds = collect($options)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($requestedYearId && in_array($requestedYearId, $optionIds, true)) {
            return $requestedYearId;
        }

        if ($student->currentEnrollment?->academic_year_id && in_array((int) $student->currentEnrollment->academic_year_id, $optionIds, true)) {
            return (int) $student->currentEnrollment->academic_year_id;
        }

        return $optionIds[0] ?? null;
    }

    private function widgetRegistry(User $user): array
    {
        return [
            'profile_summary' => ['enabled' => true, 'permission' => 'student.view_dashboard'],
            'academic_overview' => ['enabled' => true, 'permission' => 'student.view_dashboard'],
            'attendance' => ['enabled' => $this->isPermissionAllowed($user, 'student.view_attendance'), 'permission' => 'student.view_attendance'],
            'results' => ['enabled' => $this->isPermissionAllowed($user, 'student.view_result'), 'permission' => 'student.view_result'],
            'fee' => ['enabled' => $this->isPermissionAllowed($user, 'student.view_fee'), 'permission' => 'student.view_fee'],
            'admit_card' => ['enabled' => $this->isPermissionAllowed($user, 'student.view_admit_card'), 'permission' => 'student.view_admit_card'],
            'notice_board' => ['enabled' => $this->isPermissionAllowed($user, 'student.view_notice_board'), 'permission' => 'student.view_notice_board'],
            'assignments' => ['enabled' => $this->isPermissionAllowed($user, 'student.view_assignments'), 'permission' => 'student.view_assignments'],
            'timetable' => ['enabled' => $this->isPermissionAllowed($user, 'student.view_timetable'), 'permission' => 'student.view_timetable'],
            'academic_history' => ['enabled' => $this->isPermissionAllowed($user, 'student.view_academic_history'), 'permission' => 'student.view_academic_history'],
            'attendance_history' => ['enabled' => $this->isPermissionAllowed($user, 'student.view_attendance_history'), 'permission' => 'student.view_attendance_history'],
        ];
    }

    private function buildTimetable(?Enrollment $selectedEnrollment, ?int $selectedYearId): array
    {
        $days = [
            ['value' => 'monday', 'label' => 'Monday'],
            ['value' => 'tuesday', 'label' => 'Tuesday'],
            ['value' => 'wednesday', 'label' => 'Wednesday'],
            ['value' => 'thursday', 'label' => 'Thursday'],
            ['value' => 'friday', 'label' => 'Friday'],
            ['value' => 'saturday', 'label' => 'Saturday'],
        ];

        $slots = DB::table('time_slots')
            ->orderBy('slot_order')
            ->orderBy('start_time')
            ->get(['id', 'name', 'start_time', 'end_time', 'is_break', 'slot_order'])
            ->map(fn ($slot) => [
                'id' => (int) $slot->id,
                'name' => (string) $slot->name,
                'start_time' => $slot->start_time,
                'end_time' => $slot->end_time,
                'time_range' => substr((string) $slot->start_time, 0, 5) . ' - ' . substr((string) $slot->end_time, 0, 5),
                'is_break' => (bool) $slot->is_break,
                'slot_order' => (int) $slot->slot_order,
            ])
            ->values()
            ->all();

        if (!$selectedEnrollment?->section_id || !$selectedYearId) {
            return [
                'source' => 'timetables',
                'days' => $days,
                'slots' => $slots,
                'items' => [],
            ];
        }

        $dayOrderSql = "CASE t.day_of_week
            WHEN 'monday' THEN 1
            WHEN 'tuesday' THEN 2
            WHEN 'wednesday' THEN 3
            WHEN 'thursday' THEN 4
            WHEN 'friday' THEN 5
            WHEN 'saturday' THEN 6
            ELSE 7 END";

        $items = DB::table('timetables as t')
            ->join('time_slots as ts', 'ts.id', '=', 't.time_slot_id')
            ->leftJoin('subjects as s', 's.id', '=', 't.subject_id')
            ->leftJoin('users as u', 'u.id', '=', 't.teacher_id')
            ->where('t.section_id', (int) $selectedEnrollment->section_id)
            ->where('t.academic_year_id', (int) $selectedYearId)
            ->orderByRaw($dayOrderSql)
            ->orderBy('ts.slot_order')
            ->get([
                't.day_of_week',
                't.time_slot_id',
                'ts.name as period_name',
                'ts.slot_order',
                'ts.start_time',
                'ts.end_time',
                'ts.is_break',
                's.name as subject_name',
                'u.first_name',
                'u.last_name',
                't.room_number',
            ])
            ->map(function ($row) {
                $teacherName = trim((string) (($row->first_name ?? '') . ' ' . ($row->last_name ?? '')));
                return [
                    'day_key' => (string) $row->day_of_week,
                    'day' => ucfirst((string) $row->day_of_week),
                    'time_slot_id' => (int) $row->time_slot_id,
                    'period' => $row->period_name,
                    'time_slot_order' => (int) $row->slot_order,
                    'time' => substr((string) $row->start_time, 0, 5) . ' - ' . substr((string) $row->end_time, 0, 5),
                    'is_break' => (bool) $row->is_break,
                    'subject' => $row->subject_name ?? ((bool) $row->is_break ? 'Break' : '-'),
                    'teacher' => $teacherName !== '' ? $teacherName : '-',
                    'room_number' => $row->room_number,
                ];
            })
            ->values()
            ->all();

        return [
            'source' => 'timetables',
            'days' => $days,
            'slots' => $slots,
            'items' => $items,
        ];
    }

    private function buildAcademicHistory(int $studentId): array
    {
        $items = Enrollment::query()
            ->with(['academicYear:id,name', 'classModel:id,name', 'section:id,name,class_id', 'section.class:id,name'])
            ->where('student_id', $studentId)
            ->orderByDesc('academic_year_id')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Enrollment $enrollment) => [
                'enrollment_id' => (int) $enrollment->id,
                'academic_year_id' => (int) $enrollment->academic_year_id,
                'academic_year' => $enrollment->academicYear?->name,
                'class' => $enrollment->classModel?->name ?? $enrollment->section?->class?->name,
                'section' => $enrollment->section?->name,
                'roll_number' => $enrollment->roll_number,
                'status' => $enrollment->status,
                'enrollment_date' => optional($enrollment->enrollment_date)->toDateString(),
                'is_locked' => (bool) $enrollment->is_locked,
            ])
            ->values()
            ->all();

        return [
            'source' => 'enrollments',
            'items' => $items,
        ];
    }

    private function buildAttendanceHistory(array $enrollmentIdsInYear, ?int $selectedYearId): array
    {
        if (empty($enrollmentIdsInYear)) {
            return ['source' => 'attendance_monthly_summaries', 'items' => []];
        }

        $items = DB::table('attendance_monthly_summaries as ams')
            ->join('enrollments as e', 'e.id', '=', 'ams.enrollment_id')
            ->whereIn('ams.enrollment_id', $enrollmentIdsInYear)
            ->when($selectedYearId, fn ($q) => $q->where('ams.academic_year_id', $selectedYearId))
            ->orderByDesc('ams.month')
            ->limit(24)
            ->get([
                'ams.month',
                'ams.present_count',
                'ams.absent_count',
                'ams.leave_count',
                'ams.half_day_count',
                'ams.total_count',
                'ams.attendance_percentage',
                'e.roll_number',
            ])
            ->map(fn ($row) => [
                'month' => (string) $row->month,
                'roll_number' => $row->roll_number,
                'present' => (int) $row->present_count,
                'absent' => (int) $row->absent_count,
                'leave' => (int) $row->leave_count,
                'half_day' => (int) $row->half_day_count,
                'total' => (int) $row->total_count,
                'attendance_percentage' => (float) $row->attendance_percentage,
            ])
            ->values()
            ->all();

        if (!empty($items)) {
            return [
                'source' => 'attendance_monthly_summaries',
                'items' => $items,
            ];
        }

        $rows = DB::table('attendances as a')
            ->join('enrollments as e', 'e.id', '=', 'a.enrollment_id')
            ->whereIn('a.enrollment_id', $enrollmentIdsInYear)
            ->when($selectedYearId, fn ($q) => $q->where('e.academic_year_id', $selectedYearId))
            ->orderByDesc('a.date')
            ->get([
                'a.date',
                'a.status',
                'e.roll_number',
            ]);

        $items = $rows
            ->groupBy(function ($row) {
                $month = Carbon::parse((string) $row->date)->format('Y-m');
                return $month . '|' . (string) ($row->roll_number ?? '');
            })
            ->map(function ($group) {
                $first = $group->first();
                $present = $group->where('status', 'present')->count();
                $absent = $group->where('status', 'absent')->count();
                $leave = $group->where('status', 'leave')->count();
                $halfDay = $group->where('status', 'half_day')->count();
                $total = $group->count();

                return [
                    'month' => Carbon::parse((string) $first->date)->format('Y-m'),
                    'roll_number' => $first->roll_number,
                    'present' => $present,
                    'absent' => $absent,
                    'leave' => $leave,
                    'half_day' => $halfDay,
                    'total' => $total,
                    'attendance_percentage' => $total > 0 ? round((($present + $halfDay) / $total) * 100, 2) : 0,
                ];
            })
            ->sortByDesc('month')
            ->take(24)
            ->values()
            ->all();

        return [
            'source' => 'attendances',
            'items' => $items,
        ];
    }

    private function isPermissionAllowed(User $user, string $code): bool
    {
        $permissionExists = Permission::query()->where('code', $code)->exists();
        if (!$permissionExists) {
            return true;
        }

        return $user->hasPermission($code);
    }

    private function auditView(Student $student, ?int $selectedYearId, string $month, array $result): void
    {
        AuditLog::log(
            'student.dashboard.view',
            $student,
            null,
            [
                'academic_year_id' => $selectedYearId,
                'month' => $month,
            ],
            'Student dashboard viewed'
        );

        if (($result['state'] ?? null) === 'available' && isset($result['latest_result']['student_result_id'])) {
            AuditLog::log(
                'student.result.view',
                $student,
                null,
                [
                    'student_result_id' => (int) $result['latest_result']['student_result_id'],
                    'academic_year_id' => $selectedYearId,
                ],
                'Student result viewed from dashboard'
            );
        }
    }

    private function emptyAttendance(string $month): array
    {
        return [
            'month' => $month,
            'monthly_percentage' => 0,
            'total_present' => 0,
            'total_absent' => 0,
            'total_leave' => 0,
            'total_half_day' => 0,
            'last_7_days' => [],
            'source' => 'permission_denied',
        ];
    }

    private function emptyResult(): array
    {
        return [
            'state' => 'not_published',
            'message' => 'Result access is not enabled for your account.',
            'latest_result' => null,
            'download_url' => null,
            'download_available' => false,
        ];
    }

    private function emptyFeeSummary(): array
    {
        return [
            'total_fee' => 0,
            'paid_amount' => 0,
            'pending_amount' => 0,
            'last_payment_date' => null,
            'last_receipt_number' => null,
            'receipt_download_url' => null,
            'receipt_download_available' => false,
            'source' => 'permission_denied',
        ];
    }

    private function emptyAdmitCard(): array
    {
        return [
            'status' => 'blocked',
            'exam_name' => null,
            'download_url' => null,
            'message' => 'Admit card access is not enabled for your account.',
            'version' => null,
            'admit_card_id' => null,
            'published_at' => null,
        ];
    }
}
