<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\Student;
use App\Models\TimeSlot;
use App\Models\Timetable;
use App\Models\User;
use App\Services\Email\EventNotificationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TimetableController extends Controller
{
    public function timeSlots()
    {
        return response()->json(
            TimeSlot::query()->orderBy('slot_order')->orderBy('start_time')->get()
        );
    }

    public function storeTimeSlot(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'is_break' => 'sometimes|boolean',
            'slot_order' => 'required|integer|min:1|max:999',
        ]);

        $slot = TimeSlot::query()->create([
            'name' => $validated['name'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'is_break' => (bool) ($validated['is_break'] ?? false),
            'slot_order' => (int) $validated['slot_order'],
        ]);

        return response()->json([
            'message' => 'Time slot created successfully.',
            'data' => $slot,
        ], 201);
    }

    public function updateTimeSlot(Request $request, int $id)
    {
        $slot = TimeSlot::query()->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i',
            'is_break' => 'sometimes|boolean',
            'slot_order' => 'sometimes|integer|min:1|max:999',
        ]);

        $merged = array_merge([
            'start_time' => $slot->start_time,
            'end_time' => $slot->end_time,
        ], $validated);

        if (strtotime((string) $merged['end_time']) <= strtotime((string) $merged['start_time'])) {
            return response()->json([
                'message' => 'End time must be after start time.',
            ], 422);
        }

        $slot->update($validated);

        return response()->json([
            'message' => 'Time slot updated successfully.',
            'data' => $slot->fresh(),
        ]);
    }

    public function destroyTimeSlot(int $id)
    {
        $slot = TimeSlot::query()->findOrFail($id);

        if ($slot->timetables()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a time slot that is already used in timetable rows.',
            ], 422);
        }

        $slot->delete();

        return response()->json([
            'message' => 'Time slot deleted successfully.',
        ]);
    }

    public function getSectionTimetable(Request $request)
    {
        $validated = $request->validate([
            'academic_year_id' => 'required|integer|exists:academic_years,id',
            'section_id' => 'required|integer|exists:sections,id',
        ]);

        return response()->json(
            $this->buildSectionTimetableResponse((int) $validated['academic_year_id'], (int) $validated['section_id'])
        );
    }

    public function downloadSectionTimetablePdf(Request $request)
    {
        $validated = $request->validate([
            'academic_year_id' => 'required|integer|exists:academic_years,id',
            'section_id' => 'required|integer|exists:sections,id',
        ]);

        $payload = $this->buildSectionTimetableResponse((int) $validated['academic_year_id'], (int) $validated['section_id']);
        $title = trim((string) (($payload['meta']['class_name'] ?? 'Class') . ' - ' . ($payload['meta']['section_name'] ?? 'Section')));
        $subtitle = trim((string) ($payload['meta']['academic_year_name'] ?? ''));

        return $this->downloadPdf(
            title: $title !== '' ? $title . ' Timetable' : 'Section Timetable',
            subtitle: $subtitle,
            payload: $payload,
            filename: 'section-timetable-' . ($payload['meta']['section_name'] ?? 'section') . '.pdf'
        );
    }

    public function studentTimetable(Request $request)
    {
        $student = $this->resolveStudent($request);
        $academicYearId = $request->filled('academic_year_id') ? (int) $request->input('academic_year_id') : null;

        return response()->json($this->buildStudentTimetableResponse($student, $academicYearId));
    }

    public function downloadStudentTimetablePdf(Request $request)
    {
        $student = $this->resolveStudent($request);
        $academicYearId = $request->filled('academic_year_id') ? (int) $request->input('academic_year_id') : null;
        $payload = $this->buildStudentTimetableResponse($student, $academicYearId);
        $title = trim((string) ($payload['meta']['student_name'] ?? 'Student')) . ' Timetable';
        $subtitle = collect([
            $payload['meta']['class_name'] ?? null,
            $payload['meta']['section_name'] ?? null,
            $payload['meta']['academic_year_name'] ?? null,
        ])->filter()->implode(' | ');

        return $this->downloadPdf(
            title: $title,
            subtitle: $subtitle,
            payload: $payload,
            filename: 'student-timetable-' . ($payload['meta']['student_admission_number'] ?? 'student') . '.pdf'
        );
    }

    public function teacherTimetable(Request $request)
    {
        $teacher = $this->requireTeacher($request);
        $academicYearId = $request->filled('academic_year_id') ? (int) $request->input('academic_year_id') : null;

        return response()->json($this->buildTeacherTimetableResponse($teacher, $academicYearId));
    }

    public function saveSectionTimetable(Request $request)
    {
        $validated = $request->validate([
            'academic_year_id' => 'required|integer|exists:academic_years,id',
            'section_id' => 'required|integer|exists:sections,id',
            'entries' => 'required|array|max:200',
            'entries.*.day_of_week' => ['required', Rule::in(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'])],
            'entries.*.time_slot_id' => 'required|integer|exists:time_slots,id',
            'entries.*.subject_id' => 'nullable|integer|exists:subjects,id',
            'entries.*.teacher_id' => 'nullable|integer|exists:users,id',
            'entries.*.room_number' => 'nullable|string|max:50',
        ]);

        $section = $this->resolveSection((int) $validated['section_id'], (int) $validated['academic_year_id']);
        $classId = (int) $section->class_id;
        $teacherIds = collect($validated['entries'])->pluck('teacher_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($teacherIds->isNotEmpty()) {
            $invalidTeacherIds = User::query()
                ->whereIn('id', $teacherIds->all())
                ->get()
                ->reject(fn (User $user) => $user->hasRole(['teacher', 'staff']))
                ->pluck('id')
                ->values()
                ->all();

            if (!empty($invalidTeacherIds)) {
                return response()->json([
                    'message' => 'Only teacher or staff users can be assigned in timetable rows.',
                    'invalid_teacher_ids' => $invalidTeacherIds,
                ], 422);
            }
        }

        $subjectIds = collect($validated['entries'])->pluck('subject_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($subjectIds->isNotEmpty()) {
            $mappedSubjectIds = DB::table('class_subjects')
                ->where('class_id', $classId)
                ->where('academic_year_id', (int) $validated['academic_year_id'])
                ->whereIn('subject_id', $subjectIds->all())
                ->pluck('subject_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $unmapped = $subjectIds->reject(fn ($id) => in_array($id, $mappedSubjectIds, true))->values()->all();
            if (!empty($unmapped)) {
                return response()->json([
                    'message' => 'One or more subjects are not mapped to the selected class and academic year.',
                    'invalid_subject_ids' => $unmapped,
                ], 422);
            }
        }

        $teacherConflicts = collect($validated['entries'])
            ->filter(fn ($entry) => !empty($entry['teacher_id']))
            ->map(function ($entry) use ($validated, $section) {
                $existing = Timetable::query()
                    ->where('academic_year_id', (int) $validated['academic_year_id'])
                    ->where('teacher_id', (int) $entry['teacher_id'])
                    ->where('day_of_week', (string) $entry['day_of_week'])
                    ->where('time_slot_id', (int) $entry['time_slot_id'])
                    ->where('section_id', '!=', $section->id)
                    ->first();

                if (!$existing) {
                    return null;
                }

                return [
                    'teacher_id' => (int) $entry['teacher_id'],
                    'day_of_week' => (string) $entry['day_of_week'],
                    'time_slot_id' => (int) $entry['time_slot_id'],
                    'conflict_section_id' => (int) $existing->section_id,
                ];
            })
            ->filter()
            ->values()
            ->all();

        if (!empty($teacherConflicts)) {
            return response()->json([
                'message' => 'One or more teachers are already booked for the selected day and slot.',
                'conflicts' => $teacherConflicts,
            ], 422);
        }

        DB::transaction(function () use ($validated, $section) {
            foreach ($validated['entries'] as $entry) {
                $attributes = [
                    'academic_year_id' => (int) $validated['academic_year_id'],
                    'section_id' => $section->id,
                    'day_of_week' => (string) $entry['day_of_week'],
                    'time_slot_id' => (int) $entry['time_slot_id'],
                ];

                $isEmptyRow = empty($entry['subject_id']) && empty($entry['teacher_id']) && empty(trim((string) ($entry['room_number'] ?? '')));

                if ($isEmptyRow) {
                    Timetable::query()->where($attributes)->delete();
                    continue;
                }

                Timetable::query()->updateOrCreate(
                    $attributes,
                    [
                        'subject_id' => !empty($entry['subject_id']) ? (int) $entry['subject_id'] : null,
                        'teacher_id' => !empty($entry['teacher_id']) ? (int) $entry['teacher_id'] : null,
                        'room_number' => !empty($entry['room_number']) ? trim((string) $entry['room_number']) : null,
                    ]
                );
            }
        });

        app(EventNotificationService::class)->notifyTimetableUpdated(
            $section,
            (int) $validated['academic_year_id'],
            $teacherIds->all()
        );

        return response()->json([
            'message' => 'Timetable saved successfully.',
        ]);
    }

    private function resolveSection(int $sectionId, int $academicYearId): Section
    {
        $section = Section::query()->with('class')->findOrFail($sectionId);

        if ((int) $section->academic_year_id !== $academicYearId) {
            abort(422, 'Selected section does not belong to the selected academic year.');
        }

        return $section;
    }

    private function buildSectionTimetableResponse(int $academicYearId, int $sectionId): array
    {
        $section = $this->resolveSection($sectionId, $academicYearId);
        $academicYear = AcademicYear::query()->find($academicYearId);
        $rows = $this->querySectionRows($academicYearId, $section->id);
        $slots = $this->timeSlotPayload();

        return [
            'meta' => [
                'academic_year_id' => $academicYearId,
                'academic_year_name' => $academicYear?->name,
                'section_id' => (int) $section->id,
                'class_id' => (int) $section->class_id,
                'section_name' => $section->name,
                'class_name' => $section->class?->name,
            ],
            'days' => $this->dayPayload(),
            'slots' => $slots,
            'rows' => $rows->values()->all(),
            'matrix' => $this->buildMatrix($rows, $slots),
        ];
    }

    private function buildStudentTimetableResponse(Student $student, ?int $academicYearId): array
    {
        $enrollment = Enrollment::query()
            ->with(['section.class', 'classModel', 'academicYear', 'student.user'])
            ->where('student_id', (int) $student->id)
            ->when($academicYearId, fn ($query) => $query->where('academic_year_id', $academicYearId))
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('enrollment_date')
            ->orderByDesc('id')
            ->first();

        if (!$enrollment?->section_id || !$enrollment?->academic_year_id) {
            return [
                'meta' => [
                    'student_id' => (int) $student->id,
                    'student_name' => trim((string) ($student->user?->full_name ?? '')),
                    'student_admission_number' => $student->admission_number,
                    'academic_year_id' => $academicYearId,
                    'academic_year_name' => null,
                    'section_id' => null,
                    'section_name' => null,
                    'class_name' => null,
                ],
                'days' => $this->dayPayload(),
                'slots' => $this->timeSlotPayload(),
                'rows' => [],
                'matrix' => [],
            ];
        }

        $response = $this->buildSectionTimetableResponse((int) $enrollment->academic_year_id, (int) $enrollment->section_id);
        $response['meta'] = [
            ...$response['meta'],
            'student_id' => (int) $student->id,
            'student_name' => trim((string) ($student->user?->full_name ?? '')),
            'student_admission_number' => $student->admission_number,
            'roll_number' => $enrollment->roll_number,
        ];

        return $response;
    }

    private function buildTeacherTimetableResponse(User $teacher, ?int $academicYearId): array
    {
        $baseQuery = Timetable::query()
            ->with([
                'timeSlot:id,name,start_time,end_time,is_break,slot_order',
                'subject:id,name,subject_code,code',
                'teacher:id,first_name,last_name',
                'section:id,class_id,name,academic_year_id',
                'section.class:id,name',
                'academicYear:id,name,is_current',
            ])
            ->where('teacher_id', (int) $teacher->id);

        $resolvedAcademicYearId = $academicYearId;
        if (!$resolvedAcademicYearId) {
            $resolvedAcademicYearId = (int) ((clone $baseQuery)
                ->join('academic_years as ay', 'ay.id', '=', 'timetables.academic_year_id')
                ->orderByDesc('ay.is_current')
                ->orderByDesc('timetables.academic_year_id')
                ->value('timetables.academic_year_id') ?? 0);
        }

        $rows = collect();
        if ($resolvedAcademicYearId > 0) {
            $rows = $baseQuery
                ->where('academic_year_id', $resolvedAcademicYearId)
                ->orderByRaw($this->dayOrderSql('timetables.day_of_week'))
                ->orderBy(TimeSlot::query()->select('slot_order')->whereColumn('time_slots.id', 'timetables.time_slot_id'))
                ->get()
                ->map(fn (Timetable $row) => $this->mapTimetableRow($row, true))
                ->values();
        }

        $availableAcademicYears = Timetable::query()
            ->where('teacher_id', (int) $teacher->id)
            ->join('academic_years as ay', 'ay.id', '=', 'timetables.academic_year_id')
            ->select('ay.id', 'ay.name', 'ay.is_current')
            ->distinct()
            ->orderByDesc('ay.is_current')
            ->orderByDesc('ay.id')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'is_current' => (bool) $row->is_current,
            ])
            ->values()
            ->all();

        $slots = $this->timeSlotPayload();
        $currentAcademicYear = $resolvedAcademicYearId > 0 ? AcademicYear::query()->find($resolvedAcademicYearId) : null;

        return [
            'meta' => [
                'teacher_id' => (int) $teacher->id,
                'teacher_name' => trim((string) $teacher->full_name),
                'academic_year_id' => $resolvedAcademicYearId > 0 ? $resolvedAcademicYearId : null,
                'academic_year_name' => $currentAcademicYear?->name,
            ],
            'academic_year_options' => $availableAcademicYears,
            'days' => $this->dayPayload(),
            'slots' => $slots,
            'rows' => $rows->all(),
            'matrix' => $this->buildMatrix($rows, $slots),
        ];
    }

    private function querySectionRows(int $academicYearId, int $sectionId): Collection
    {
        return Timetable::query()
            ->with([
                'timeSlot:id,name,start_time,end_time,is_break,slot_order',
                'subject:id,name,subject_code,code',
                'teacher:id,first_name,last_name',
                'section:id,class_id,name,academic_year_id',
                'section.class:id,name',
                'academicYear:id,name,is_current',
            ])
            ->where('academic_year_id', $academicYearId)
            ->where('section_id', $sectionId)
            ->orderByRaw($this->dayOrderSql('timetables.day_of_week'))
            ->orderBy(TimeSlot::query()->select('slot_order')->whereColumn('time_slots.id', 'timetables.time_slot_id'))
            ->get()
            ->map(fn (Timetable $row) => $this->mapTimetableRow($row, true))
            ->values();
    }

    private function mapTimetableRow(Timetable $row, bool $includeScope = false): array
    {
        $teacherName = $row->teacher ? trim($row->teacher->first_name . ' ' . $row->teacher->last_name) : null;
        $payload = [
            'id' => (int) $row->id,
            'academic_year_id' => (int) $row->academic_year_id,
            'academic_year_name' => $row->academicYear?->name,
            'section_id' => (int) $row->section_id,
            'day_of_week' => $row->day_of_week,
            'day_label' => ucfirst((string) $row->day_of_week),
            'time_slot_id' => (int) $row->time_slot_id,
            'subject_id' => $row->subject_id !== null ? (int) $row->subject_id : null,
            'teacher_id' => $row->teacher_id !== null ? (int) $row->teacher_id : null,
            'room_number' => $row->room_number,
            'time_slot_name' => $row->timeSlot?->name,
            'time_slot_order' => $row->timeSlot?->slot_order,
            'start_time' => $row->timeSlot?->start_time,
            'end_time' => $row->timeSlot?->end_time,
            'time_range' => trim(substr((string) ($row->timeSlot?->start_time ?? ''), 0, 5) . ' - ' . substr((string) ($row->timeSlot?->end_time ?? ''), 0, 5), ' -'),
            'is_break' => (bool) ($row->timeSlot?->is_break ?? false),
            'subject_name' => $row->subject?->name,
            'subject_code' => $row->subject?->subject_code ?: $row->subject?->code,
            'teacher_name' => $teacherName,
        ];

        if ($includeScope) {
            $payload['class_name'] = $row->section?->class?->name;
            $payload['section_name'] = $row->section?->name;
        }

        return $payload;
    }

    private function dayPayload(): array
    {
        return [
            ['value' => 'monday', 'label' => 'Monday'],
            ['value' => 'tuesday', 'label' => 'Tuesday'],
            ['value' => 'wednesday', 'label' => 'Wednesday'],
            ['value' => 'thursday', 'label' => 'Thursday'],
            ['value' => 'friday', 'label' => 'Friday'],
            ['value' => 'saturday', 'label' => 'Saturday'],
        ];
    }

    private function timeSlotPayload(): array
    {
        return TimeSlot::query()
            ->orderBy('slot_order')
            ->orderBy('start_time')
            ->get()
            ->map(fn (TimeSlot $slot) => [
                'id' => (int) $slot->id,
                'name' => $slot->name,
                'start_time' => $slot->start_time,
                'end_time' => $slot->end_time,
                'time_range' => substr((string) $slot->start_time, 0, 5) . ' - ' . substr((string) $slot->end_time, 0, 5),
                'is_break' => (bool) $slot->is_break,
                'slot_order' => (int) $slot->slot_order,
            ])
            ->values()
            ->all();
    }

    private function buildMatrix(Collection $rows, array $slots): array
    {
        return collect($slots)
            ->map(function (array $slot) use ($rows) {
                $cells = collect($this->dayPayload())
                    ->mapWithKeys(function (array $day) use ($rows, $slot) {
                        $match = $rows->first(fn (array $row) => $row['day_of_week'] === $day['value'] && $row['time_slot_id'] === $slot['id']);
                        return [$day['value'] => $match];
                    })
                    ->all();

                return [
                    'slot' => $slot,
                    'days' => $cells,
                ];
            })
            ->values()
            ->all();
    }

    private function dayOrderSql(string $column): string
    {
        return "CASE {$column}
            WHEN 'monday' THEN 1
            WHEN 'tuesday' THEN 2
            WHEN 'wednesday' THEN 3
            WHEN 'thursday' THEN 4
            WHEN 'friday' THEN 5
            WHEN 'saturday' THEN 6
            ELSE 7 END";
    }

    private function resolveStudent(Request $request): Student
    {
        $user = $request->user();
        if (!$user || !$user->hasRole('student')) {
            abort(403, 'Only student users can access this endpoint.');
        }

        $student = $user->student()->with('user')->first();
        if (!$student) {
            abort(404, 'Student profile not found for authenticated user.');
        }

        return $student;
    }

    private function requireTeacher(Request $request): User
    {
        $user = $request->user();
        if (!$user || !$user->hasRole('teacher')) {
            abort(403, 'Teacher access required.');
        }

        return $user;
    }

    private function downloadPdf(string $title, string $subtitle, array $payload, string $filename)
    {
        $pdf = Pdf::loadView('timetables.pdf', [
            'title' => $title,
            'subtitle' => $subtitle,
            'payload' => $payload,
            'generated_on' => now()->format('d M Y h:i A'),
        ])->setPaper('a4', 'landscape');

        $pdf->setOption(['isRemoteEnabled' => true]);

        return $pdf->download($filename);
    }
}
