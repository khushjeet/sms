<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\SchoolEvent;
use App\Models\StudentResult;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InAppNotificationService
{
    public function notifyPaymentRecorded(Payment $payment): void
    {
        $payment->loadMissing(['enrollment.student.user', 'enrollment.student.parents.user']);

        $student = $payment->enrollment?->student;
        if (!$student) {
            return;
        }

        $title = 'Fee payment received';
        $message = sprintf(
            'Payment of %s was recorded%s.',
            number_format((float) $payment->amount, 2),
            $payment->receipt_number ? ' with receipt ' . $payment->receipt_number : ''
        );

        $rows = [];

        if ($student->user) {
            $rows[] = $this->buildRowForUser($student->user, [
                'title' => $title,
                'message' => $message,
                'type' => 'finance',
                'priority' => 'important',
                'entity_type' => 'payment',
                'entity_id' => (int) $payment->id,
                'action_target' => '/student/fee',
                'audience_type' => 'single_user',
            ]);
        }

        foreach ($student->parents as $parent) {
            if ($parent->user) {
                $rows[] = $this->buildRowForUser($parent->user, [
                    'title' => $title,
                    'message' => $message,
                    'type' => 'finance',
                    'priority' => 'important',
                    'entity_type' => 'payment',
                    'entity_id' => (int) $payment->id,
                    'action_target' => '/dashboard',
                    'audience_type' => 'single_user',
                ]);
            }
        }

        $this->safeInsert($rows, 'payment-recorded');
    }

    public function notifyResultPublishedByIds(array $resultIds): void
    {
        if ($resultIds === []) {
            return;
        }

        StudentResult::query()
            ->with(['student.user', 'student.parents.user', 'examSession.classModel'])
            ->whereIn('id', $resultIds)
            ->get()
            ->each(function (StudentResult $result): void {
                $student = $result->student;
                if (!$student) {
                    return;
                }

                $examName = (string) ($result->examSession?->name ?: 'Exam');
                $className = (string) ($result->examSession?->classModel?->name ?: 'Class');
                $title = 'Result published';
                $message = sprintf('%s result is now available for %s.', $examName, $className);

                $rows = [];

                if ($student->user) {
                    $rows[] = $this->buildRowForUser($student->user, [
                        'title' => $title,
                        'message' => $message,
                        'type' => 'result',
                        'priority' => 'important',
                        'entity_type' => 'student_result',
                        'entity_id' => (int) $result->id,
                        'action_target' => '/student/result',
                        'audience_type' => 'single_user',
                    ]);
                }

                foreach ($student->parents as $parent) {
                    if ($parent->user) {
                        $rows[] = $this->buildRowForUser($parent->user, [
                            'title' => $title,
                            'message' => $message,
                            'type' => 'result',
                            'priority' => 'important',
                            'entity_type' => 'student_result',
                            'entity_id' => (int) $result->id,
                            'action_target' => '/parent/result',
                            'audience_type' => 'single_user',
                        ]);
                    }
                }

                $this->safeInsert($rows, 'result-published');
            });
    }

    public function notifyLeaveSubmitted(int $leaveId): void
    {
        $leave = DB::table('staff_leaves')
            ->leftJoin('staff', 'staff.id', '=', 'staff_leaves.staff_id')
            ->leftJoin('users', 'users.id', '=', 'staff.user_id')
            ->where('staff_leaves.id', $leaveId)
            ->select(
                'staff_leaves.id',
                'staff_leaves.staff_id',
                'staff_leaves.start_date',
                'staff_leaves.end_date',
                'staff_leaves.total_days',
                'users.first_name',
                'users.last_name'
            )
            ->first();

        if (!$leave) {
            return;
        }

        $staffName = trim(((string) $leave->first_name) . ' ' . ((string) $leave->last_name)) ?: 'Staff member';
        $message = sprintf(
            '%s submitted a leave request for %s day(s) from %s to %s.',
            $staffName,
            (string) $leave->total_days,
            (string) $leave->start_date,
            (string) $leave->end_date
        );

        $users = User::query()
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereIn('role', ['super_admin', 'school_admin'])
                    ->orWhereHas('roles', function ($roleQuery): void {
                        $roleQuery->whereIn('name', ['super_admin', 'school_admin']);
                    });
            })
            ->get();

        $rows = $users
            ->map(fn (User $user) => $this->buildRowForUser($user, [
                'title' => 'Leave request submitted',
                'message' => $message,
                'type' => 'leave',
                'priority' => 'action_required',
                'entity_type' => 'leave_request',
                'entity_id' => $leaveId,
                'action_target' => '/hr-payroll',
                'audience_type' => 'role_group',
            ]))
            ->all();

        $this->safeInsert($rows, 'leave-submitted');
    }

    public function notifyLeaveDecision(int $leaveId): void
    {
        $leave = DB::table('staff_leaves')
            ->leftJoin('staff', 'staff.id', '=', 'staff_leaves.staff_id')
            ->leftJoin('users', 'users.id', '=', 'staff.user_id')
            ->where('staff_leaves.id', $leaveId)
            ->select('staff_leaves.id', 'staff_leaves.status', 'users.id as user_id', 'users.role')
            ->first();

        if (!$leave || !$leave->user_id) {
            return;
        }

        $user = User::query()->find((int) $leave->user_id);
        if (!$user) {
            return;
        }

        $status = (string) $leave->status;
        $this->safeInsert([
            $this->buildRowForUser($user, [
                'title' => $status === 'approved' ? 'Leave request approved' : 'Leave request rejected',
                'message' => $status === 'approved'
                    ? 'Your leave request has been approved.'
                    : 'Your leave request has been rejected.',
                'type' => 'leave',
                'priority' => $status === 'approved' ? 'important' : 'normal',
                'entity_type' => 'leave_request',
                'entity_id' => $leaveId,
                'action_target' => '/hr-payroll',
                'audience_type' => 'single_user',
            ]),
        ], 'leave-decision');
    }

    public function notifyAttendanceLocked(array $scope, string $date): void
    {
        $query = User::query()->where('status', 'active')->whereIn('role', ['super_admin', 'school_admin', 'teacher']);

        if (!empty($scope['section_id'])) {
            $teacherIds = DB::table('teacher_subject_assignments')
                ->where('section_id', (int) $scope['section_id'])
                ->pluck('teacher_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $query->where(function ($inner) use ($teacherIds): void {
                $inner->whereIn('role', ['super_admin', 'school_admin']);
                if ($teacherIds !== []) {
                    $inner->orWhereIn('id', $teacherIds);
                }
            });
        }

        $message = 'Attendance was locked for ' . $date . '.';
        if (!empty($scope['class_id'])) {
            $message .= ' Class ID: ' . (int) $scope['class_id'] . '.';
        }
        if (!empty($scope['section_id'])) {
            $message .= ' Section ID: ' . (int) $scope['section_id'] . '.';
        }

        $rows = $query
            ->get()
            ->map(fn (User $user) => $this->buildRowForUser($user, [
                'title' => 'Attendance locked',
                'message' => $message,
                'type' => 'attendance',
                'priority' => 'important',
                'entity_type' => 'attendance_lock',
                'entity_id' => null,
                'action_target' => $user->hasRole('teacher') ? '/teacher/mark-attendance' : '/attendance',
                'class_id' => $scope['class_id'] ?? null,
                'section_id' => $scope['section_id'] ?? null,
                'audience_type' => !empty($scope['section_id']) ? 'class_section' : 'role_group',
            ]))
            ->all();

        $this->safeInsert($rows, 'attendance-locked');
    }

    public function notifyStudentAttendanceMarked(User $actor, array $scope, string $date, int $count): void
    {
        $actorName = trim($actor->full_name) !== '' ? trim($actor->full_name) : 'A teacher';
        $message = sprintf(
            '%s marked student attendance for %d record(s) on %s.',
            $actorName,
            $count,
            $date
        );

        if (!empty($scope['class_id'])) {
            $message .= ' Class ID: ' . (int) $scope['class_id'] . '.';
        }
        if (!empty($scope['section_id'])) {
            $message .= ' Section ID: ' . (int) $scope['section_id'] . '.';
        }

        $recipients = $this->adminUsers();

        if ($actor->hasRole(['super_admin', 'school_admin'])) {
            $recipients = $recipients->push($actor)->unique('id')->values();
        }

        $rows = $recipients
            ->map(fn (User $user) => $this->buildRowForUser($user, [
                'title' => 'Student attendance marked',
                'message' => $message,
                'type' => 'attendance',
                'priority' => 'important',
                'entity_type' => 'attendance_mark',
                'entity_id' => null,
                'action_target' => '/attendance',
                'class_id' => $scope['class_id'] ?? null,
                'section_id' => $scope['section_id'] ?? null,
                'audience_type' => !empty($scope['section_id']) ? 'class_section' : 'role_group',
                'meta' => [
                    'marked_by_user_id' => (int) $actor->id,
                    'marked_count' => $count,
                    'attendance_date' => $date,
                ],
            ]))
            ->all();

        $this->safeInsert($rows, 'student-attendance-marked');
    }

    public function notifySelfAttendanceMarked(User $actor, string $punchType, string $punchedAt): void
    {
        $staff = $actor->staff;
        $actorName = trim($actor->full_name) !== '' ? trim($actor->full_name) : 'A teacher';
        $title = $punchType === 'in' ? 'Self attendance punch-in' : 'Self attendance punch-out';
        $message = sprintf('%s marked self attendance (%s) at %s.', $actorName, strtoupper($punchType), $punchedAt);

        if ($staff?->employee_id) {
            $message .= ' Employee ID: ' . $staff->employee_id . '.';
        }

        $admins = $this->adminUsers();
        $rows = $admins
            ->map(fn (User $user) => $this->buildRowForUser($user, [
                'title' => $title,
                'message' => $message,
                'type' => 'attendance',
                'priority' => 'important',
                'entity_type' => 'self_attendance',
                'entity_id' => null,
                'action_target' => '/hr-payroll',
                'audience_type' => 'role_group',
                'meta' => [
                    'marked_by_user_id' => (int) $actor->id,
                    'punch_type' => $punchType,
                    'punched_at' => $punchedAt,
                ],
            ]))
            ->all();

        $this->safeInsert($rows, 'self-attendance-marked');
    }

    public function notifyEventPublished(SchoolEvent $event, string $action): void
    {
        if ($event->status !== 'published') {
            return;
        }

        $verb = $action === 'updated' ? 'updated' : 'created';
        $message = sprintf('%s has been %s.', $event->title, $verb);

        $rows = User::query()
            ->where('status', 'active')
            ->get()
            ->map(fn (User $user) => $this->buildRowForUser($user, [
                'title' => 'School event ' . $verb,
                'message' => $message,
                'type' => 'event',
                'priority' => 'normal',
                'entity_type' => 'event',
                'entity_id' => (int) $event->id,
                'action_target' => $user->hasRole('super_admin') ? '/admin/events' : '/dashboard',
                'audience_type' => 'school_wide',
            ]))
            ->all();

        $this->safeInsert($rows, 'event-published');
    }

    public function insertForUsers(iterable $users, array $payload): void
    {
        $rows = [];

        foreach ($users as $user) {
            if (!$user instanceof User) {
                continue;
            }

            $rows[] = $this->buildRowForUser($user, $payload);
        }

        $this->safeInsert($rows, 'generic-insert');
    }

    private function buildRowForUser(User $user, array $payload): array
    {
        return [
            'school_id' => $payload['school_id'] ?? null,
            'user_id' => (int) $user->id,
            'role' => $payload['role'] ?? ($user->getPrimaryRole() ?? $user->role),
            'class_id' => $payload['class_id'] ?? null,
            'section_id' => $payload['section_id'] ?? null,
            'audience_type' => $payload['audience_type'] ?? null,
            'title' => trim((string) ($payload['title'] ?? 'Notification')),
            'message' => trim((string) ($payload['message'] ?? '')),
            'type' => trim((string) ($payload['type'] ?? 'announcement')),
            'priority' => trim((string) ($payload['priority'] ?? 'normal')),
            'entity_type' => $payload['entity_type'] ?? null,
            'entity_id' => $payload['entity_id'] ?? null,
            'action_target' => $payload['action_target'] ?? null,
            'meta' => isset($payload['meta']) ? json_encode($payload['meta'], JSON_UNESCAPED_UNICODE) : null,
            'is_read' => false,
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function safeInsert(array $rows, string $context): void
    {
        $rows = collect($rows)
            ->filter(fn ($row) => !empty($row['user_id']) && !empty($row['message']))
            ->unique(fn ($row) => $row['user_id'] . '|' . ($row['type'] ?? '') . '|' . ($row['entity_type'] ?? '') . '|' . ($row['entity_id'] ?? ''))
            ->values()
            ->all();

        if ($rows === []) {
            return;
        }

        try {
            DB::table('user_notifications')->insert($rows);
        } catch (\Throwable $exception) {
            Log::warning('In-app notification write failed.', [
                'context' => $context,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function adminUsers()
    {
        return User::query()
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereIn('role', ['super_admin', 'school_admin'])
                    ->orWhereHas('roles', function ($roleQuery): void {
                        $roleQuery->whereIn('name', ['super_admin', 'school_admin']);
                    });
            })
            ->get();
    }
}
