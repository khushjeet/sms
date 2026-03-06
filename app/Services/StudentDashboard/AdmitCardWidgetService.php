<?php

namespace App\Services\StudentDashboard;

use App\Models\AdmitCard;
use Illuminate\Support\Facades\Cache;

class AdmitCardWidgetService
{
    private const HIDDEN_ADMIT_MESSAGE = 'Admit card is not available currently. Please contact administration.';

    public function build(int $studentId, ?int $academicYearId): array
    {
        $cacheKey = implode(':', [
            'student_dashboard',
            'admit_card',
            $studentId,
            (int) ($academicYearId ?? 0),
        ]);

        return Cache::remember($cacheKey, now()->addMinutes(3), function () use ($studentId, $academicYearId) {
            $query = AdmitCard::query()
                ->with([
                    'examSession:id,name,academic_year_id,class_id,published_at',
                    'latestVisibility:admit_visibility_controls.id,admit_visibility_controls.admit_card_id,admit_visibility_controls.visibility_status',
                ])
                ->where('student_id', $studentId)
                ->where('is_superseded', false)
                ->orderByDesc('generated_at')
                ->orderByDesc('id');

            if ($academicYearId) {
                $query->whereHas('examSession', fn ($q) => $q->where('academic_year_id', $academicYearId));
            }

            /** @var AdmitCard|null $admit */
            $admit = $query->first();

            if (!$admit) {
                return [
                    'status' => 'not_generated',
                    'exam_name' => null,
                    'download_url' => null,
                    'message' => 'Admit card is not generated yet.',
                    'version' => null,
                    'admit_card_id' => null,
                    'published_at' => null,
                ];
            }

            $visibility = $admit->latestVisibility?->visibility_status ?? 'visible';
            if ($visibility !== 'visible' || $admit->status === 'blocked') {
                return [
                    'status' => 'blocked',
                    'exam_name' => $admit->examSession?->name,
                    'download_url' => null,
                    'message' => self::HIDDEN_ADMIT_MESSAGE,
                    'version' => (int) $admit->version,
                    'admit_card_id' => (int) $admit->id,
                    'published_at' => $admit->published_at?->toDateTimeString(),
                ];
            }

            if ($admit->status === 'draft') {
                return [
                    'status' => 'generated_not_published',
                    'exam_name' => $admit->examSession?->name,
                    'download_url' => null,
                    'message' => 'Admit card is generated and under preparation.',
                    'version' => (int) $admit->version,
                    'admit_card_id' => (int) $admit->id,
                    'published_at' => null,
                ];
            }

            return [
                'status' => 'published',
                'exam_name' => $admit->examSession?->name,
                'download_url' => "/api/v1/admits/{$admit->id}/paper",
                'message' => 'Admit card is available for download.',
                'version' => (int) $admit->version,
                'admit_card_id' => (int) $admit->id,
                'published_at' => $admit->published_at?->toDateTimeString(),
            ];
        });
    }
}
