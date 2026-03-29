<?php

namespace App\Services\StudentDashboard;

use App\Models\StudentResult;
use Illuminate\Support\Facades\Cache;

class ResultWidgetService
{
    private const HIDDEN_RESULT_MESSAGE = 'Result is not available for you currently. Please contact administration.';

    public function build(int $studentId, ?int $academicYearId): array
    {
        $cacheKey = implode(':', [
            'student_dashboard',
            'result',
            $studentId,
            (int) ($academicYearId ?? 0),
        ]);

        return Cache::remember($cacheKey, now()->addMinutes(3), function () use ($studentId, $academicYearId) {
            $query = StudentResult::query()
                ->with([
                    'examSession:id,name,academic_year_id,class_id,published_at',
                    'examSession.classModel:id,name',
                    'examSession.academicYear:id,name',
                    'latestVisibility:result_visibility_controls.id,result_visibility_controls.student_result_id,result_visibility_controls.visibility_status',
                ])
                ->where('student_id', $studentId)
                ->where('is_superseded', false);

            if ($academicYearId) {
                $query->whereHas('examSession', fn ($q) => $q->where('academic_year_id', $academicYearId));
            }

            /** @var StudentResult|null $result */
            $result = $query
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->first();

            if (!$result || !$result->published_at) {
                return [
                    'state' => 'not_published',
                    'message' => 'Result not yet published.',
                    'latest_result' => null,
                    'download_url' => null,
                    'download_available' => false,
                ];
            }

            $visibility = $result->latestVisibility?->visibility_status ?? 'visible';
            if ($visibility !== 'visible') {
                return [
                    'state' => 'blocked',
                    'message' => self::HIDDEN_RESULT_MESSAGE,
                    'latest_result' => null,
                    'download_url' => null,
                    'download_available' => false,
                ];
            }

            return [
                'state' => 'available',
                'message' => null,
                'download_url' => route('results.paper', ['studentResultId' => (int) $result->id], false),
                'download_available' => true,
                'latest_result' => [
                    'student_result_id' => (int) $result->id,
                    'exam_name' => $result->examSession?->name,
                    'class_name' => $result->examSession?->classModel?->name,
                    'academic_year' => $result->examSession?->academicYear?->name,
                    'percentage' => (float) $result->percentage,
                    'grade' => $result->grade,
                    'result_status' => $result->result_status,
                    'published_at' => $result->published_at?->toDateTimeString(),
                ],
            ];
        });
    }
}
