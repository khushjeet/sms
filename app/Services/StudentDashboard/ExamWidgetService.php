<?php

namespace App\Services\StudentDashboard;

use App\Models\Enrollment;
use App\Models\ExamSession;
use Illuminate\Support\Facades\Cache;

class ExamWidgetService
{
    public function upcoming(?Enrollment $enrollment, ?int $academicYearId): array
    {
        $cacheKey = implode(':', [
            'student_dashboard',
            'upcoming_exam',
            (int) ($enrollment?->id ?? 0),
            (int) ($academicYearId ?? 0),
        ]);

        return Cache::remember($cacheKey, now()->addMinutes(3), function () use ($enrollment, $academicYearId) {
            $query = ExamSession::query()
                ->with(['academicYear:id,name', 'classModel:id,name', 'examConfiguration:id,name'])
                ->whereNotNull('published_at')
                ->orderBy('published_at');

            if ($academicYearId) {
                $query->where('academic_year_id', $academicYearId);
            }
            if ($enrollment?->class_id) {
                $query->where('class_id', (int) $enrollment->class_id);
            }

            /** @var ExamSession|null $upcoming */
            $upcoming = $query->first();

            if (!$upcoming) {
                return [
                    'name' => null,
                    'status' => 'not_scheduled',
                    'published_at' => null,
                    'term' => null,
                    'exam_session_id' => null,
                ];
            }

            return [
                'name' => $upcoming->name,
                'status' => $upcoming->status,
                'published_at' => $upcoming->published_at?->toDateString(),
                'term' => $upcoming->examConfiguration?->name,
                'exam_session_id' => (int) $upcoming->id,
            ];
        });
    }

    public function admitCard(array $resultSection, array $upcomingExam): array
    {
        if ($resultSection['state'] === 'blocked') {
            return [
                'status' => 'blocked',
                'exam_name' => $upcomingExam['name'],
                'download_url' => null,
                'message' => 'Admit card is blocked by administration.',
                'version' => 1,
            ];
        }

        if (empty($upcomingExam['name'])) {
            return [
                'status' => 'not_published',
                'exam_name' => null,
                'download_url' => null,
                'message' => 'Admit card not published.',
                'version' => null,
            ];
        }

        if (($upcomingExam['status'] ?? null) === 'locked') {
            return [
                'status' => 'expired',
                'exam_name' => $upcomingExam['name'],
                'download_url' => null,
                'message' => 'Admit card expired for this exam session.',
                'version' => 1,
            ];
        }

        return [
            'status' => 'available',
            'exam_name' => $upcomingExam['name'],
            'download_url' => null,
            'message' => 'Admit card is available when download service is enabled.',
            'version' => 1,
        ];
    }
}
