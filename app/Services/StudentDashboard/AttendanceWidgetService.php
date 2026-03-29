<?php

namespace App\Services\StudentDashboard;

use App\Models\Attendance;
use App\Models\AttendanceMonthlySummary;
use App\Models\Enrollment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class AttendanceWidgetService
{
    public function build(?Enrollment $enrollment, string $month): array
    {
        if (!$enrollment) {
            return [
                'month' => $month,
                'monthly_percentage' => 0,
                'total_present' => 0,
                'total_absent' => 0,
                'total_leave' => 0,
                'total_half_day' => 0,
                'last_7_days' => [],
                'source' => 'attendance_monthly_summaries',
            ];
        }

        $cacheKey = implode(':', [
            'student_dashboard',
            'attendance',
            (int) $enrollment->id,
            $month,
        ]);

        return Cache::remember($cacheKey, now()->addMinutes(3), function () use ($enrollment, $month) {
            $summary = AttendanceMonthlySummary::query()
                ->where('enrollment_id', (int) $enrollment->id)
                ->where('month', $month)
                ->first();

            $monthStart = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();

            if (!$summary) {
                $monthAttendances = Attendance::query()
                    ->where('enrollment_id', (int) $enrollment->id)
                    ->whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                    ->get(['status']);

                $presentCount = $monthAttendances->where('status', 'present')->count();
                $absentCount = $monthAttendances->where('status', 'absent')->count();
                $leaveCount = $monthAttendances->where('status', 'leave')->count();
                $halfDayCount = $monthAttendances->where('status', 'half_day')->count();
                $totalCount = $monthAttendances->count();
                $attendancePercentage = $totalCount > 0
                    ? round((($presentCount + $halfDayCount) / $totalCount) * 100, 2)
                    : 0;
            } else {
                $presentCount = (int) $summary->present_count;
                $absentCount = (int) $summary->absent_count;
                $leaveCount = (int) $summary->leave_count;
                $halfDayCount = (int) $summary->half_day_count;
                $totalCount = (int) $summary->total_count;
                $attendancePercentage = (float) $summary->attendance_percentage;
            }

            $last7 = Attendance::query()
                ->where('enrollment_id', (int) $enrollment->id)
                ->orderByDesc('date')
                ->limit(7)
                ->get(['date', 'status'])
                ->map(fn (Attendance $attendance) => [
                    'date' => optional($attendance->date)->toDateString(),
                    'status' => $attendance->status,
                ])
                ->all();

            return [
                'month' => $month,
                'monthly_percentage' => $attendancePercentage,
                'total_present' => $presentCount,
                'total_absent' => $absentCount,
                'total_leave' => $leaveCount,
                'total_half_day' => $halfDayCount,
                'last_7_days' => $last7,
                'source' => $summary ? 'attendance_monthly_summaries' : 'attendances',
            ];
        });
    }
}
