<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\SchoolEvent;
use App\Models\SchoolEventParticipant;
use App\Models\SchoolSetting;
use App\Services\InAppNotificationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SchoolEventController extends Controller
{
    public function index(Request $request)
    {
        $query = SchoolEvent::query()
            ->with('academicYear:id,name')
            ->withCount('participants')
            ->withCount([
                'participants as ranked_count' => fn ($q) => $q->whereNotNull('rank'),
            ])
            ->orderByDesc('event_date')
            ->orderByDesc('id');

        if ($request->filled('academic_year_id')) {
            $query->where('academic_year_id', (int) $request->input('academic_year_id'));
        }

        if ($request->filled('search')) {
            $search = '%' . trim((string) $request->input('search')) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', $search)
                    ->orWhere('venue', 'like', $search);
            });
        }

        return response()->json($query->paginate((int) $request->input('per_page', 20)));
    }

    public function store(Request $request)
    {
        $validated = $this->validateEvent($request);
        $event = SchoolEvent::query()->create($validated);
        app(InAppNotificationService::class)->notifyEventPublished($event, 'created');

        return response()->json([
            'message' => 'Event created successfully.',
            'data' => $event->load('academicYear:id,name'),
        ], 201);
    }

    public function show(int $id)
    {
        return response()->json($this->eventDetailQuery()->findOrFail($id));
    }

    public function update(Request $request, int $id)
    {
        $event = SchoolEvent::query()->findOrFail($id);
        $event->update($this->validateEvent($request));
        app(InAppNotificationService::class)->notifyEventPublished($event->fresh(), 'updated');

        return response()->json([
            'message' => 'Event updated successfully.',
            'data' => $event->fresh()->load('academicYear:id,name'),
        ]);
    }

    public function destroy(int $id)
    {
        $event = SchoolEvent::query()->findOrFail($id);
        $event->delete();

        return response()->json([
            'message' => 'Event deleted successfully.',
        ]);
    }

    public function syncParticipants(Request $request, int $id)
    {
        $event = SchoolEvent::query()->findOrFail($id);

        $validated = $request->validate([
            'participants' => ['required', 'array', 'max:500'],
            'participants.*.id' => ['nullable', 'integer', 'exists:school_event_participants,id'],
            'participants.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'participants.*.enrollment_id' => ['nullable', 'integer', 'exists:enrollments,id'],
            'participants.*.rank' => ['nullable', 'integer', Rule::in([1, 2, 3])],
            'participants.*.achievement_title' => ['nullable', 'string', 'max:255'],
            'participants.*.remarks' => ['nullable', 'string', 'max:255'],
        ]);

        $rows = collect($validated['participants'])->values();
        $participantIds = $rows->pluck('id')->filter()->map(fn ($value) => (int) $value)->all();

        $foreignIds = SchoolEventParticipant::query()
            ->whereIn('id', $participantIds)
            ->where('school_event_id', '!=', $event->id)
            ->pluck('id')
            ->all();

        if (!empty($foreignIds)) {
            throw ValidationException::withMessages([
                'participants' => ['One or more participant rows do not belong to this event.'],
            ]);
        }

        $studentIds = $rows->pluck('student_id')->map(fn ($value) => (int) $value)->all();
        if (count($studentIds) !== count(array_unique($studentIds))) {
            throw ValidationException::withMessages([
                'participants' => ['A student can be added only once per event.'],
            ]);
        }

        $ranked = $rows->pluck('rank')->filter(fn ($value) => !is_null($value))->map(fn ($value) => (int) $value)->all();
        if (count($ranked) !== count(array_unique($ranked))) {
            throw ValidationException::withMessages([
                'participants' => ['Each rank can be assigned only once per event.'],
            ]);
        }

        $enrollmentIds = $rows->pluck('enrollment_id')->filter()->map(fn ($value) => (int) $value)->all();
        $enrollmentMap = Enrollment::query()->whereIn('id', $enrollmentIds)->get()->keyBy('id');

        foreach ($rows as $row) {
            $enrollmentId = isset($row['enrollment_id']) && $row['enrollment_id'] !== null ? (int) $row['enrollment_id'] : null;
            if ($enrollmentId === null) {
                continue;
            }

            /** @var Enrollment|null $enrollment */
            $enrollment = $enrollmentMap->get($enrollmentId);
            if (!$enrollment || (int) $enrollment->student_id !== (int) $row['student_id']) {
                throw ValidationException::withMessages([
                    'participants' => ['Selected enrollment does not belong to the selected student.'],
                ]);
            }

            if ($event->academic_year_id !== null && (int) $enrollment->academic_year_id !== (int) $event->academic_year_id) {
                throw ValidationException::withMessages([
                    'participants' => ['Participant enrollment must belong to the event academic year.'],
                ]);
            }
        }

        DB::transaction(function () use ($event, $rows) {
            $keepIds = [];

            foreach ($rows as $row) {
                $participant = null;

                if (!empty($row['id'])) {
                    $participant = SchoolEventParticipant::query()
                        ->where('school_event_id', $event->id)
                        ->find((int) $row['id']);
                }

                if (!$participant) {
                    $participant = SchoolEventParticipant::query()->firstOrNew([
                        'school_event_id' => $event->id,
                        'student_id' => (int) $row['student_id'],
                    ]);
                }

                $participant->fill([
                    'student_id' => (int) $row['student_id'],
                    'enrollment_id' => isset($row['enrollment_id']) && $row['enrollment_id'] !== null ? (int) $row['enrollment_id'] : null,
                    'rank' => isset($row['rank']) && $row['rank'] !== null ? (int) $row['rank'] : null,
                    'achievement_title' => isset($row['achievement_title']) ? trim((string) $row['achievement_title']) ?: null : null,
                    'remarks' => isset($row['remarks']) ? trim((string) $row['remarks']) ?: null : null,
                ]);
                $participant->save();

                $keepIds[] = (int) $participant->id;
            }

            $deleteQuery = SchoolEventParticipant::query()->where('school_event_id', $event->id);
            if (!empty($keepIds)) {
                $deleteQuery->whereNotIn('id', $keepIds);
            }
            $deleteQuery->delete();
        });

        return response()->json([
            'message' => 'Participants updated successfully.',
            'data' => $this->eventDetailQuery()->findOrFail($event->id),
        ]);
    }

    public function certificatePdf(Request $request, int $participantId)
    {
        $participant = SchoolEventParticipant::query()
            ->with([
                'event.academicYear:id,name',
                'student.user:id,first_name,last_name',
                'student.profile',
                'enrollment.section.class',
                'enrollment.classModel',
                'enrollment.academicYear:id,name',
            ])
            ->findOrFail($participantId);

        $type = strtolower(trim((string) $request->input('type', 'participant')));
        if (!in_array($type, ['participant', 'winner'], true)) {
            abort(422, 'Invalid certificate type.');
        }

        if ($type === 'winner' && !in_array((int) $participant->rank, [1, 2, 3], true)) {
            abort(422, 'Winner certificate is available only for 1st, 2nd, or 3rd rank.');
        }

        $pdf = Pdf::loadView('events.certificate', $this->certificatePayload($participant, $type))->setPaper('a4', 'landscape');
        $pdf->setOption(['isRemoteEnabled' => true]);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $this->certificateFilename($participant, $type) . '"',
        ]);
    }

    private function validateEvent(Request $request): array
    {
        return $request->validate([
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'title' => ['required', 'string', 'max:255'],
            'event_date' => ['nullable', 'date'],
            'venue' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['draft', 'published', 'archived'])],
            'certificate_prefix' => ['nullable', 'string', 'max:20'],
        ]);
    }

    private function eventDetailQuery()
    {
        return SchoolEvent::query()->with([
            'academicYear:id,name',
            'participants.student.user:id,first_name,last_name',
            'participants.student.profile',
            'participants.enrollment.section.class',
            'participants.enrollment.classModel',
            'participants.enrollment.academicYear:id,name',
        ]);
    }

    private function certificatePayload(SchoolEventParticipant $participant, string $type): array
    {
        $studentName = trim((string) ($participant->student?->user?->full_name ?? $participant->student?->full_name ?? 'Student'));
        $className = $participant->enrollment?->section?->class?->name
            ?? $participant->enrollment?->classModel?->name
            ?? $participant->student?->profile?->class?->name
            ?? 'N/A';
        $sectionName = $participant->enrollment?->section?->name ?? 'N/A';

        return [
            'certificate' => [
                'type' => $type,
                'headline' => $type === 'winner' ? 'Certificate of Achievement' : 'Certificate of Participation',
                'title' => $participant->event?->title ?? 'School Event',
                'event_date' => $participant->event?->event_date?->format('d M Y'),
                'venue' => $participant->event?->venue,
                'student_name' => $studentName,
                'admission_number' => $participant->student?->admission_number,
                'class_section' => trim($className . ' / ' . $sectionName, ' /'),
                'rank' => $participant->rank,
                'rank_label' => match ((int) $participant->rank) {
                    1 => '1st Rank',
                    2 => '2nd Rank',
                    3 => '3rd Rank',
                    default => null,
                },
                'achievement_title' => $participant->achievement_title,
                'remarks' => $participant->remarks,
                'description' => $type === 'winner'
                    ? 'For outstanding performance and distinguished achievement in the event.'
                    : 'For enthusiastic participation and commendable spirit in the event.',
                'certificate_number' => $this->certificateNumber($participant, $type),
                'generated_on' => now()->format('d M Y'),
            ],
            'school' => $this->schoolCertificatePayload(),
        ];
    }

    private function schoolCertificatePayload(): array
    {
        $schoolName = SchoolSetting::getValue('school_name', config('school.name', config('app.name')));
        $logo = SchoolSetting::getValue('school_logo_url', config('school.logo_url'));
        $watermark = SchoolSetting::getValue('school_watermark_logo_url');

        return [
            'name' => $schoolName,
            'address' => SchoolSetting::getValue('school_address', config('school.address')),
            'phone' => SchoolSetting::getValue('school_phone', config('school.phone')),
            'website' => SchoolSetting::getValue('school_website', config('school.website')),
            'registration_number' => SchoolSetting::getValue('school_registration_number', config('school.reg_no')),
            'logo' => $this->assetDataUrl((string) ($logo ?? '')),
            'watermark_logo' => $this->assetDataUrl((string) ($watermark ?: $logo ?: '')),
            'principal_signature' => $this->assetDataUrl((string) (SchoolSetting::getValue('principal_signature_path') ?? '')),
            'director_signature' => $this->assetDataUrl((string) (SchoolSetting::getValue('director_signature_path') ?? '')),
        ];
    }

    private function assetDataUrl(string $value): ?string
    {
        $path = $this->resolveAssetPath($value);
        if ($path === null || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $mime = mime_content_type($path) ?: null;
        if ($mime === null || !str_starts_with($mime, 'image/')) {
            return null;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        return 'data:' . $mime . ';base64,' . base64_encode($contents);
    }

    private function resolveAssetPath(string $value): ?string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^data:/i', $normalized) === 1) {
            return null;
        }

        if (preg_match('/^https?:/i', $normalized) === 1) {
            $parsedPath = parse_url($normalized, PHP_URL_PATH);
            $normalized = is_string($parsedPath) ? $parsedPath : '';
        }

        $normalized = ltrim(str_replace('\\', '/', $normalized), '/');
        $storageRelative = preg_replace('/^(public\/storage\/|storage\/|public\/)/', '', $normalized);
        $storageRelative = is_string($storageRelative) ? ltrim($storageRelative, '/') : $normalized;

        $candidates = [
            file_exists($normalized) ? $normalized : null,
            public_path($normalized),
            public_path('storage/' . $storageRelative),
            Storage::disk('public')->exists($storageRelative) ? Storage::disk('public')->path($storageRelative) : null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function certificateNumber(SchoolEventParticipant $participant, string $type): string
    {
        $prefix = trim((string) ($participant->event?->certificate_prefix ?: 'EVT'));

        return sprintf(
            '%s-%d-%d-%s',
            Str::upper($prefix),
            (int) $participant->school_event_id,
            (int) $participant->id,
            Str::upper($type === 'winner' ? 'WIN' : 'PART')
        );
    }

    private function certificateFilename(SchoolEventParticipant $participant, string $type): string
    {
        $eventSlug = Str::slug((string) ($participant->event?->title ?: 'event'));
        $studentSlug = Str::slug((string) ($participant->student?->user?->full_name ?: $participant->student?->admission_number ?: 'student'));

        return "{$eventSlug}-{$studentSlug}-{$type}-certificate.pdf";
    }
}
