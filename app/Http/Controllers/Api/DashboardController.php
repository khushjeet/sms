<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\ClassModel;
use App\Models\Enrollment;
use App\Models\Expense;
use App\Models\FeeAssignment;
use App\Models\Payment;
use App\Models\Section;
use App\Models\Staff;
use App\Models\StaffAttendancePolicy;
use App\Models\StaffAttendanceApprovalLog;
use App\Models\StaffAttendancePunchEvent;
use App\Models\StaffAttendanceRecord;
use App\Models\StaffAttendanceSession;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class DashboardController extends Controller
{
    public function superAdmin(Request $request)
    {
        return response()->json($this->buildDashboardData('super_admin'));
    }

    public function schoolAdmin(Request $request)
    {
        return response()->json($this->buildDashboardData('school_admin'));
    }

    public function notifications(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401, 'Unauthenticated.');

        $audiences = $this->targetAudiencesForUser($user);

        $items = DB::table('notifications')
            ->leftJoin('notification_reads as nr', function ($join) use ($user) {
                $join->on('nr.notification_id', '=', 'notifications.id')
                    ->where('nr.user_id', '=', $user->id);
            })
            ->where('notifications.status', 'sent')
            ->where(function ($query) use ($audiences) {
                $query->whereIn('notifications.target_audience', $audiences)
                    ->orWhere('notifications.target_audience', 'all');
            })
            ->where(function ($query) {
                $query->whereNull('notifications.sent_at')
                    ->orWhere('notifications.sent_at', '<=', now());
            })
            ->orderByDesc('notifications.sent_at')
            ->orderByDesc('notifications.id')
            ->limit(20)
            ->get([
                'notifications.id',
                'notifications.title',
                'notifications.message',
                'notifications.type',
                'notifications.target_audience',
                'notifications.sent_at',
                'nr.read_at',
            ])
            ->map(function ($item) {
                return [
                    'id' => (int) $item->id,
                    'title' => (string) $item->title,
                    'message' => (string) $item->message,
                    'type' => (string) $item->type,
                    'target_audience' => (string) $item->target_audience,
                    'sent_at' => $item->sent_at,
                    'is_read' => !is_null($item->read_at),
                ];
            })
            ->values();

        return response()->json([
            'items' => $items,
        ]);
    }

    public function selfAttendanceStatus(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401, 'Unauthenticated.');

        $staff = Staff::query()->where('user_id', $user->id)->first();
        if (!$staff) {
            return response()->json([
                'can_mark' => false,
                'message' => 'Your account is not linked with an employee profile.',
                'session' => null,
                'recent_events' => [],
            ]);
        }

        $today = Carbon::today(config('app.timezone'));
        $session = StaffAttendanceSession::query()
            ->with(['punchEvents' => function ($query) {
                $query->orderByDesc('punched_at')->limit(6);
            }])
            ->where('staff_id', $staff->id)
            ->whereDate('attendance_date', $today->toDateString())
            ->first();

        $recentEvents = ($session?->punchEvents ?? collect())
            ->map(function (StaffAttendancePunchEvent $event) {
                return [
                    'id' => (int) $event->id,
                    'punch_type' => $event->punch_type,
                    'punched_at' => optional($event->punched_at)?->toIso8601String(),
                    'latitude' => $event->latitude !== null ? (float) $event->latitude : null,
                    'longitude' => $event->longitude !== null ? (float) $event->longitude : null,
                    'location_accuracy_meters' => $event->location_accuracy_meters,
                    'selfie_url' => $this->selfieUrl($event->selfie_path),
                    'source' => $event->source,
                ];
            })
            ->values();

        return response()->json([
            'can_mark' => true,
            'message' => null,
            'session' => $session ? [
                'id' => (int) $session->id,
                'attendance_date' => optional($session->attendance_date)?->toDateString(),
                'punch_in_at' => optional($session->punch_in_at)?->toIso8601String(),
                'punch_out_at' => optional($session->punch_out_at)?->toIso8601String(),
                'duration_minutes' => $session->duration_minutes,
                'review_status' => $session->review_status,
                'punch_in_selfie_url' => $this->selfieUrl($session->punch_in_selfie_path),
                'punch_out_selfie_url' => $this->selfieUrl($session->punch_out_selfie_path),
            ] : null,
            'can_punch_in' => !$session || !$session->punch_in_at,
            'can_punch_out' => (bool) ($session?->punch_in_at && !$session?->punch_out_at),
            'recent_events' => $recentEvents,
        ]);
    }

    public function markSelfAttendance(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401, 'Unauthenticated.');

        $staff = Staff::query()->where('user_id', $user->id)->first();
        if (!$staff) {
            return response()->json([
                'message' => 'Your account is not linked with an employee profile.',
            ], 403);
        }

        $validated = $request->validate([
            'punch_type' => 'required|in:in,out',
            'selfie' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:6144',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'location_accuracy_meters' => 'nullable|integer|min:0|max:5000',
            'device_id' => 'nullable|string|max:191',
            'timezone' => 'nullable|string|max:64',
            'note' => 'nullable|string|max:1000',
        ]);

        $timezone = (string) ($validated['timezone'] ?? config('app.timezone'));
        $attendanceDate = Carbon::now($timezone)->toDateString();
        $nowUtc = now()->utc();

        $policy = StaffAttendancePolicy::query()
            ->whereDate('effective_from', '<=', $attendanceDate)
            ->where(function ($query) use ($attendanceDate) {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $attendanceDate);
            })
            ->orderByDesc('effective_from')
            ->first();

        if (($policy?->require_selfie ?? true) && !$request->hasFile('selfie')) {
            return response()->json([
                'message' => 'Selfie is required for attendance.',
            ], 422);
        }

        $payload = DB::transaction(function () use (
            $request,
            $validated,
            $staff,
            $attendanceDate,
            $nowUtc,
            $timezone,
            $policy,
            $user
        ) {
            $session = StaffAttendanceSession::query()->firstOrCreate(
                [
                    'staff_id' => $staff->id,
                    'attendance_date' => $attendanceDate,
                ],
                [
                    'attendance_policy_id' => $policy?->id,
                    'timezone' => $timezone,
                    'review_status' => 'pending',
                    'marked_by_user_id' => $user?->id,
                ]
            );

            $selfiePath = null;
            $selfieHash = null;
            $selfieMeta = null;

            if ($request->hasFile('selfie')) {
                $file = $request->file('selfie');
                $selfiePath = $file->store('attendance/selfies/' . $staff->id, 'public');
                $selfieHash = hash_file('sha256', (string) $file->getRealPath());
                $selfieMeta = [
                    'original_name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                ];
            }

            if ($validated['punch_type'] === 'in') {
                if ($session->punch_in_at) {
                    abort(422, 'Punch-in already marked for today.');
                }

                $session->update([
                    'attendance_policy_id' => $session->attendance_policy_id ?: $policy?->id,
                    'punch_in_at' => $nowUtc,
                    'punch_in_selfie_path' => $selfiePath ?: $session->punch_in_selfie_path,
                    'punch_in_source' => $selfiePath ? 'selfie' : 'manual',
                    'timezone' => $timezone,
                    'review_status' => 'pending',
                    'marked_by_user_id' => $user?->id,
                ]);
            } else {
                if (!$session->punch_in_at) {
                    abort(422, 'Punch-in is required before punch-out.');
                }
                if ($session->punch_out_at) {
                    abort(422, 'Punch-out already marked for today.');
                }

                $duration = max(0, (int) $session->punch_in_at->diffInMinutes($nowUtc, false));
                $session->update([
                    'punch_out_at' => $nowUtc,
                    'punch_out_selfie_path' => $selfiePath ?: $session->punch_out_selfie_path,
                    'punch_out_source' => $selfiePath ? 'selfie' : 'manual',
                    'duration_minutes' => $duration,
                    'review_status' => 'pending',
                ]);
            }

            StaffAttendanceApprovalLog::query()->create([
                'staff_attendance_session_id' => $session->id,
                'from_status' => $session->getOriginal('review_status') ?? 'pending',
                'to_status' => 'pending',
                'action' => 'submitted',
                'acted_by' => $user?->id,
                'acted_at' => now(),
                'remarks' => 'Self attendance submitted from dashboard.',
            ]);

            $event = $session->punchEvents()->create([
                'staff_id' => $staff->id,
                'punch_type' => $validated['punch_type'],
                'punched_at' => $nowUtc,
                'selfie_path' => $selfiePath,
                'selfie_sha256' => $selfieHash,
                'selfie_metadata' => $selfieMeta,
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
                'location_accuracy_meters' => $validated['location_accuracy_meters'] ?? null,
                'ip_address' => $request->ip(),
                'device_id' => $validated['device_id'] ?? null,
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
                'source' => $selfiePath ? 'selfie' : 'manual',
                'is_system_generated' => false,
                'captured_by_user_id' => $user?->id,
                'note' => $validated['note'] ?? null,
            ]);

            $record = StaffAttendanceRecord::query()->firstOrNew([
                'staff_id' => $staff->id,
                'attendance_date' => $attendanceDate,
            ]);
            if (!$record->exists) {
                $record->created_by = $user?->id;
            }
            $record->staff_attendance_session_id = $session->id;
            $record->status = 'present';
            $record->source = 'session';
            $record->approval_status = 'pending';
            $record->remarks = $validated['note'] ?? null;
            $record->updated_by = $user?->id;
            $record->save();

            return [
                'message' => $validated['punch_type'] === 'in'
                    ? 'Punch-in saved successfully.'
                    : 'Punch-out saved successfully.',
                'event' => [
                    'id' => (int) $event->id,
                    'punch_type' => $event->punch_type,
                    'punched_at' => optional($event->punched_at)?->toIso8601String(),
                    'latitude' => $event->latitude !== null ? (float) $event->latitude : null,
                    'longitude' => $event->longitude !== null ? (float) $event->longitude : null,
                    'location_accuracy_meters' => $event->location_accuracy_meters,
                    'selfie_url' => $this->selfieUrl($event->selfie_path),
                ],
            ];
        });

        return response()->json($payload, 201);
    }

    private function buildDashboardData(string $dashboard): array
    {
        $today = Carbon::today();
        $currentYear = AcademicYear::current()->first();
        $currentYearId = $currentYear?->id;

        $attendanceCounts = $this->getAttendanceCounts($today, $currentYearId);
        $finance = $this->getFinanceSummary($today, $currentYearId);
        $expense = $this->getExpenseSummary($today);

        $studentsByStatus = Student::select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $enrollmentBase = Enrollment::query();
        if ($currentYearId) {
            $enrollmentBase->where('academic_year_id', $currentYearId);
        }

        $enrollmentsTotal = (clone $enrollmentBase)->count();
        $enrollmentsActive = (clone $enrollmentBase)
            ->where('status', 'active')
            ->where('is_locked', false)
            ->count();

        $sectionsQuery = Section::query();
        if ($currentYearId) {
            $sectionsQuery->where('academic_year_id', $currentYearId);
        }

        $baseCounts = [
            'students' => [
                'total' => Student::count(),
                'by_status' => [
                    'active' => (int) ($studentsByStatus['active'] ?? 0),
                    'alumni' => (int) ($studentsByStatus['alumni'] ?? 0),
                    'transferred' => (int) ($studentsByStatus['transferred'] ?? 0),
                    'dropped' => (int) ($studentsByStatus['dropped'] ?? 0),
                ],
            ],
            'classes' => ClassModel::count(),
            'sections' => $sectionsQuery->count(),
            'enrollments' => [
                'total' => $enrollmentsTotal,
                'active' => $enrollmentsActive,
            ],
        ];

        if ($dashboard === 'super_admin') {
            if (Schema::hasTable('user_roles') && Schema::hasTable('roles')) {
                $usersByRole = DB::table('user_roles')
                    ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                    ->where(function ($q) {
                        $q->whereNull('user_roles.expires_at')
                            ->orWhere('user_roles.expires_at', '>', now());
                    })
                    ->select('roles.name as role', DB::raw('count(distinct user_roles.user_id) as total'))
                    ->groupBy('roles.name')
                    ->pluck('total', 'role');
            } else {
                $usersByRole = User::select('role', DB::raw('count(*) as total'))
                    ->groupBy('role')
                    ->pluck('total', 'role');
            }

            $baseCounts['users'] = [
                'total' => User::count(),
                'by_role' => [
                    'super_admin' => (int) ($usersByRole['super_admin'] ?? 0),
                    'school_admin' => (int) ($usersByRole['school_admin'] ?? 0),
                    'accountant' => (int) ($usersByRole['accountant'] ?? 0),
                    'teacher' => (int) ($usersByRole['teacher'] ?? 0),
                    'parent' => (int) ($usersByRole['parent'] ?? 0),
                    'student' => (int) ($usersByRole['student'] ?? 0),
                    'staff' => (int) ($usersByRole['staff'] ?? 0),
                    'principal' => (int) ($usersByRole['principal'] ?? 0),
                ],
            ];
        }

        return [
            'dashboard' => $dashboard,
            'current_academic_year' => $currentYear ? [
                'id' => $currentYear->id,
                'name' => $currentYear->name,
                'start_date' => $currentYear->start_date?->toDateString(),
                'end_date' => $currentYear->end_date?->toDateString(),
                'status' => $currentYear->status,
                'is_current' => $currentYear->is_current,
            ] : null,
            'counts' => $baseCounts,
            'attendance' => $attendanceCounts,
            'finance' => $finance,
            'expense' => $expense,
        ];
    }

    private function getAttendanceCounts(Carbon $today, ?int $currentYearId): array
    {
        $attendanceQuery = Attendance::query()->whereDate('date', $today);

        if ($currentYearId) {
            $attendanceQuery->whereHas('enrollment', function ($query) use ($currentYearId) {
                $query->where('academic_year_id', $currentYearId);
            });
        }

        $counts = $attendanceQuery
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $present = (int) ($counts['present'] ?? 0);
        $absent = (int) ($counts['absent'] ?? 0);
        $leave = (int) ($counts['leave'] ?? 0);
        $halfDay = (int) ($counts['half_day'] ?? 0);

        return [
            'date' => $today->toDateString(),
            'present' => $present,
            'absent' => $absent,
            'leave' => $leave,
            'half_day' => $halfDay,
            'total' => $present + $absent + $leave + $halfDay,
        ];
    }

    private function getFinanceSummary(Carbon $today, ?int $currentYearId): array
    {
        $assignmentQuery = FeeAssignment::query();
        $paymentQuery = Payment::query()
            ->where('amount', '>', 0)
            ->whereDoesntHave('reversal');

        if ($currentYearId) {
            $assignmentQuery->whereHas('enrollment', function ($query) use ($currentYearId) {
                $query->where('academic_year_id', $currentYearId);
            });

            $paymentQuery->whereHas('enrollment', function ($query) use ($currentYearId) {
                $query->where('academic_year_id', $currentYearId);
            });
        }

        $assignedTotal = (float) $assignmentQuery->sum('total_fee');
        $collectedTotal = (float) $paymentQuery->sum('amount');
        $collectedToday = (float) (clone $paymentQuery)
            ->whereDate('payment_date', $today)
            ->sum('amount');
        $collectedMonth = (float) (clone $paymentQuery)
            ->whereYear('payment_date', $today->year)
            ->whereMonth('payment_date', $today->month)
            ->sum('amount');

        return [
            'assigned_total' => $assignedTotal,
            'collected_total' => $collectedTotal,
            'collected_today' => $collectedToday,
            'collected_month' => $collectedMonth,
            'pending_total' => max(0, $assignedTotal - $collectedTotal),
        ];
    }

    private function getExpenseSummary(Carbon $today): array
    {
        $expenseBase = Expense::query()->where('is_reversal', false);
        $reversalBase = Expense::query()->where('is_reversal', true);

        $total = (float) (clone $expenseBase)->sum('amount');
        $reversedTotal = (float) (clone $reversalBase)->sum('amount');
        $todayTotal = (float) (clone $expenseBase)->whereDate('expense_date', $today)->sum('amount');
        $monthTotal = (float) (clone $expenseBase)
            ->whereYear('expense_date', $today->year)
            ->whereMonth('expense_date', $today->month)
            ->sum('amount');

        return [
            'total' => $total,
            'reversed_total' => $reversedTotal,
            'net_total' => max(0, $total - $reversedTotal),
            'today_total' => $todayTotal,
            'month_total' => $monthTotal,
        ];
    }

    private function targetAudiencesForUser(User $user): array
    {
        $primaryRole = $user->getPrimaryRole() ?? $user->role;

        return match ($primaryRole) {
            'student' => ['students'],
            'parent' => ['parents'],
            'teacher' => ['teachers', 'staff'],
            default => ['staff'],
        };
    }

    private function selfieUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
