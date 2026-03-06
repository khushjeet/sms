<?php

namespace App\Services\StudentDashboard;

use App\Models\Attendance;
use App\Models\AttendanceMonthlySummary;
use App\Models\Enrollment;
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
                'monthly_percentage' => (float) ($summary?->attendance_percentage ?? 0),
                'total_present' => (int) ($summary?->present_count ?? 0),
                'total_absent' => (int) ($summary?->absent_count ?? 0),
                'total_leave' => (int) ($summary?->leave_count ?? 0),
                'total_half_day' => (int) ($summary?->half_day_count ?? 0),
                'last_7_days' => $last7,
                'source' => 'attendance_monthly_summaries',
            ];
        });
    }
}
