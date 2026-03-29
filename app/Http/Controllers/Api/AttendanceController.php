<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\Student;
use App\Services\InAppNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceController extends Controller
{
    /**
     * Mark attendance for a section
     */
    public function markAttendance(Request $request)
    {
        $validated = $request->validate([
            'class_id' => 'nullable|exists:classes,id',
            'section_id' => 'nullable|exists:sections,id',
            'date' => 'required|date',
            'attendances' => 'required|array',
            'attendances.*.enrollment_id' => 'required|exists:enrollments,id',
            'attendances.*.status' => 'required|in:present,absent,leave,half_day',
            'attendances.*.remarks' => 'nullable|string',
        ]);
        $scope = $this->resolveAttendanceScope($request, $validated);
        $allowedEnrollmentIds = $this->attendanceEnrollmentQuery($scope)->pluck('id')->all();

        try {
            DB::beginTransaction();

            $markedBy = Auth::id();
            $markedAt = now();
            $processedCount = 0;

            foreach ($validated['attendances'] as $attendanceData) {
                if (!in_array((int) $attendanceData['enrollment_id'], $allowedEnrollmentIds, true)) {
                    continue;
                }

                $enrollment = Enrollment::find($attendanceData['enrollment_id']);

                // Check if enrollment can receive attendance
                if (!$enrollment->canReceiveAttendance()) {
                    continue;
                }

                // Check if attendance already exists
                $existing = Attendance::where('enrollment_id', $attendanceData['enrollment_id'])
                    ->where('date', $validated['date'])
                    ->first();

                if ($existing) {
                    if ($existing->is_locked) {
                        continue; // Skip locked attendance
                    }
                    // Update existing
                    $existing->update([
                        'status' => $attendanceData['status'],
                        'remarks' => $attendanceData['remarks'] ?? null,
                        'marked_by' => $markedBy,
                        'marked_at' => $markedAt,
                    ]);
                    $processedCount++;
                } else {
                    // Create new
                    Attendance::create([
                        'enrollment_id' => $attendanceData['enrollment_id'],
                        'date' => $validated['date'],
                        'status' => $attendanceData['status'],
                        'remarks' => $attendanceData['remarks'] ?? null,
                        'marked_by' => $markedBy,
                        'marked_at' => $markedAt,
                        'is_locked' => false,
                    ]);
                    $processedCount++;
                }
            }

            DB::commit();

            $actor = $request->user();
            if ($actor && $processedCount > 0) {
                app(InAppNotificationService::class)->notifyStudentAttendanceMarked(
                    $actor,
                    $scope,
                    (string) $validated['date'],
                    $processedCount
                );
            }

            return response()->json([
                'message' => 'Attendance marked successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to mark attendance',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get attendance for a section on a specific date
     */
    public function getSectionAttendance(Request $request)
    {
        $validated = $request->validate([
            'class_id' => 'nullable|exists:classes,id',
            'section_id' => 'nullable|exists:sections,id',
            'date' => 'required|date',
        ]);
        $scope = $this->resolveAttendanceScope($request, $validated);

        $enrollments = $this->attendanceEnrollmentQuery($scope)
            ->where('status', 'active')
            ->with(['student.user', 'attendances' => function ($q) use ($validated) {
                $q->where('date', $validated['date']);
            }])
            ->get();

        $attendanceList = $enrollments->map(function ($enrollment) use ($validated) {
            $attendance = $enrollment->attendances->first();

            return [
                'enrollment_id' => $enrollment->id,
                'roll_number' => $enrollment->roll_number,
                'student_name' => $enrollment->student->full_name,
                'class_name' => $enrollment->classModel?->name,
                'section_name' => $enrollment->section?->name,
                'status' => $attendance?->status ?? 'not_marked',
                'remarks' => $attendance?->remarks,
                'marked_by' => $attendance?->markedBy ?? null,
                'marked_at' => $attendance?->marked_at,
                'is_locked' => $attendance?->is_locked ?? false,
            ];
        });

        return response()->json($attendanceList);
    }

    /**
     * Get attendance report for a student
     */
    public function getStudentAttendance(Request $request, $studentId)
    {
        $validated = $request->validate([
            'academic_year_id' => 'required|exists:academic_years,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        $enrollment = Enrollment::where('student_id', $studentId)
            ->where('academic_year_id', $validated['academic_year_id'])
            ->firstOrFail();

        $query = Attendance::where('enrollment_id', $enrollment->id);

        if ($request->has('start_date')) {
            $query->where('date', '>=', $validated['start_date']);
        }

        if ($request->has('end_date')) {
            $query->where('date', '<=', $validated['end_date']);
        }

        $attendances = $query->orderBy('date', 'desc')->get();

        $summary = [
            'total_days' => $attendances->count(),
            'present' => $attendances->where('status', 'present')->count(),
            'absent' => $attendances->where('status', 'absent')->count(),
            'leave' => $attendances->where('status', 'leave')->count(),
            'half_day' => $attendances->where('status', 'half_day')->count(),
            'percentage' => $enrollment->attendance_percentage,
            'details' => $attendances
        ];

        return response()->json($summary);
    }

    /**
     * Get attendance statistics for a section
     */
    public function getSectionStatistics(Request $request)
    {
        $validated = $request->validate([
            'class_id' => 'nullable|exists:classes,id',
            'section_id' => 'nullable|exists:sections,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
        ]);
        $scope = $this->resolveAttendanceScope($request, $validated);

        $enrollments = $this->attendanceEnrollmentQuery($scope)
            ->where('status', 'active')
            ->with(['student.user', 'attendances' => function ($q) use ($validated) {
                $q->whereBetween('date', [$validated['start_date'], $validated['end_date']]);
            }])
            ->get();

        $statistics = $enrollments->map(function ($enrollment) {
            $attendances = $enrollment->attendances;
            $total = $attendances->count();
            $present = $attendances->whereIn('status', ['present', 'half_day'])->count();
            $percentage = $total > 0 ? round(($present / $total) * 100, 2) : 0;

            return [
                'enrollment_id' => $enrollment->id,
                'roll_number' => $enrollment->roll_number,
                'student_name' => $enrollment->student->full_name,
                'total_days' => $total,
                'present' => $present,
                'absent' => $attendances->where('status', 'absent')->count(),
                'leave' => $attendances->where('status', 'leave')->count(),
                'percentage' => $percentage,
            ];
        });

        return response()->json($statistics);
    }

    /**
     * Search students by student_id (database ID or admission number) for attendance reporting.
     */
    public function searchStudentsForReports(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|string|max:100',
        ]);

        $needle = trim((string) $validated['student_id']);

        $students = Student::query()
            ->with([
                'user',
                'enrollments' => function ($q) {
                    $q->with(['academicYear', 'classModel', 'section'])
                        ->orderByDesc('academic_year_id');
                },
            ])
            ->where(function ($query) use ($needle) {
                $seeded = false;
                if (is_numeric($needle)) {
                    $query->where('id', (int) $needle);
                    $seeded = true;
                }

                $admissionQuery = $seeded ? 'orWhere' : 'where';
                $query->{$admissionQuery}('admission_number', 'like', '%' . $needle . '%')
                    ->orWhereHas('user', function ($userQuery) use ($needle) {
                        $userQuery
                            ->where('first_name', 'like', '%' . $needle . '%')
                            ->orWhere('last_name', 'like', '%' . $needle . '%')
                            ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", ['%' . $needle . '%']);
                    });
            })
            ->limit(25)
            ->get();

        $payload = $students->map(function (Student $student) {
            $latestEnrollment = $student->enrollments
                ->sortByDesc(function ($enrollment) {
                    return (string) optional($enrollment->academicYear?->start_date)->toDateString();
                })
                ->first();

            return [
                'student_id' => $student->id,
                'admission_number' => $student->admission_number,
                'student_name' => $student->full_name,
                'class' => $latestEnrollment?->classModel?->name,
                'section' => $latestEnrollment?->section?->name,
                'session' => $latestEnrollment?->academicYear?->name,
                'sessions_count' => $student->enrollments->count(),
            ];
        })->values();

        return response()->json($payload);
    }

    /**
     * Real-time search by student/admission number/enrollment_id with optional class + session filters.
     */
    public function liveSearchStudentsOrEnrollments(Request $request)
    {
        $validated = $request->validate([
            'q' => 'required|string|max:100',
            'academic_year_id' => 'nullable|exists:academic_years,id',
            'class_ids' => 'nullable|string|max:1000',
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        $needle = trim((string) $validated['q']);
        $classIds = $this->resolveIntegerCsv($validated['class_ids'] ?? null);

        $query = Enrollment::query()
            ->with(['student.user', 'academicYear', 'classModel', 'section'])
            ->where(function ($q) use ($needle) {
                if (is_numeric($needle)) {
                    $q->where('id', (int) $needle)
                        ->orWhere('student_id', (int) $needle);
                }

                $q->orWhereHas('student', function ($studentQuery) use ($needle) {
                    $studentQuery->where('admission_number', 'like', '%' . $needle . '%')
                        ->orWhereHas('user', function ($userQuery) use ($needle) {
                            $userQuery
                                ->where('first_name', 'like', '%' . $needle . '%')
                                ->orWhere('last_name', 'like', '%' . $needle . '%')
                                ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", ['%' . $needle . '%']);
                        });
                });
            });

        if (!empty($validated['academic_year_id'])) {
            $query->where('academic_year_id', (int) $validated['academic_year_id']);
        }

        if (!empty($classIds)) {
            $query->whereIn('class_id', $classIds);
        }

        if (!empty($validated['month'])) {
            $resolvedYear = null;
            if (!empty($validated['academic_year_id'])) {
                $session = AcademicYear::find((int) $validated['academic_year_id']);
                $resolvedYear = $session ? $this->resolveYearForMonthInSession($session, (int) $validated['month']) : null;
            }

            if (empty($resolvedYear)) {
                return response()->json([
                    'message' => 'Select session to filter by month.'
                ], 422);
            }

            $monthStart = Carbon::createFromDate((int) $resolvedYear, (int) $validated['month'], 1)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();
            $query->whereHas('attendances', function ($attendanceQuery) use ($monthStart, $monthEnd) {
                $attendanceQuery->whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()]);
            });
        }

        $rows = $query->limit(30)->get();

        return response()->json(
            $rows->map(function (Enrollment $enrollment) {
                return [
                    'enrollment_id' => $enrollment->id,
                    'student_id' => $enrollment->student_id,
                    'admission_number' => $enrollment->student?->admission_number,
                    'student_name' => $enrollment->student?->full_name,
                    'class' => $enrollment->classModel?->name,
                    'section' => $enrollment->section?->name,
                    'session' => $enrollment->academicYear?->name,
                ];
            })->values()
        );
    }

    /**
     * Bulk monthly data by selected classes + session for frontend table/PDF.
     */
    public function getBulkMonthlyAttendanceData(Request $request)
    {
        $validated = $request->validate([
            'class_ids' => 'required|string|max:1000',
            'academic_year_id' => 'required|exists:academic_years,id',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $classIds = $this->resolveIntegerCsv($validated['class_ids'] ?? null);
        if (empty($classIds)) {
            abort(422, 'At least one class is required.');
        }

        $session = AcademicYear::findOrFail((int) $validated['academic_year_id']);
        $resolvedYear = $this->resolveYearForMonthInSession($session, (int) $validated['month']);
        if (!$resolvedYear) {
            abort(422, 'Selected month is outside the selected session range.');
        }

        $monthStart = Carbon::createFromDate((int) $resolvedYear, (int) $validated['month'], 1)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $daysInMonth = $monthStart->daysInMonth;

        $enrollments = Enrollment::query()
            ->with([
                'student.user',
                'academicYear',
                'classModel',
                'section',
                'attendances' => function ($q) use ($monthStart, $monthEnd) {
                    $q->whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                        ->orderBy('date');
                },
            ])
            ->where('academic_year_id', (int) $validated['academic_year_id'])
            ->whereIn('class_id', $classIds)
            ->orderBy('class_id')
            ->orderBy('section_id')
            ->orderBy('roll_number')
            ->get();

        $rows = $this->buildBulkMonthlyRows($enrollments, $daysInMonth, $monthStart, (int) $resolvedYear);

        return response()->json([
            'meta' => [
                'month' => (int) $validated['month'],
                'month_name' => $monthStart->format('F'),
                'year' => (int) $resolvedYear,
                'academic_year_id' => (int) $validated['academic_year_id'],
                'days' => range(1, $daysInMonth),
                'total_students' => $rows->count(),
            ],
            'rows' => $rows->values(),
        ]);
    }

    /**
     * Download bulk monthly attendance in CSV (Excel-friendly).
     */
    public function downloadBulkMonthlyAttendanceExcel(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'class_ids' => 'required|string|max:1000',
            'academic_year_id' => 'required|exists:academic_years,id',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $classIds = $this->resolveIntegerCsv($validated['class_ids'] ?? null);
        if (empty($classIds)) {
            abort(422, 'At least one class is required.');
        }

        $session = AcademicYear::findOrFail((int) $validated['academic_year_id']);
        $resolvedYear = $this->resolveYearForMonthInSession($session, (int) $validated['month']);
        if (!$resolvedYear) {
            abort(422, 'Selected month is outside the selected session range.');
        }

        $monthStart = Carbon::createFromDate((int) $resolvedYear, (int) $validated['month'], 1)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $daysInMonth = $monthStart->daysInMonth;

        $enrollments = Enrollment::query()
            ->with([
                'student.user',
                'academicYear',
                'classModel',
                'section',
                'attendances' => function ($q) use ($monthStart, $monthEnd) {
                    $q->whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                        ->orderBy('date');
                },
            ])
            ->where('academic_year_id', (int) $validated['academic_year_id'])
            ->whereIn('class_id', $classIds)
            ->orderBy('class_id')
            ->orderBy('section_id')
            ->orderBy('roll_number')
            ->get();

        $rows = $this->buildBulkMonthlyRows($enrollments, $daysInMonth, $monthStart, (int) $resolvedYear);

        $filename = 'attendance_bulk_monthly_' . now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $dayHeaders = range(1, $daysInMonth);

        $callback = function () use ($rows, $dayHeaders) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_merge([
                'enrollment_id',
                'student_id',
                'admission_number',
                'student_name',
                'class',
                'section',
                'session',
                'month',
                'year',
                'present',
                'absent',
                'leave',
                'half_day',
                'not_marked',
            ], $dayHeaders));

            foreach ($rows as $row) {
                fputcsv($out, array_merge([
                    $row['enrollment_id'],
                    $row['student_id'],
                    $row['admission_number'],
                    $row['student_name'],
                    $row['class'],
                    $row['section'],
                    $row['session'],
                    $row['month'],
                    $row['year'],
                    $row['counts']['present'],
                    $row['counts']['absent'],
                    $row['counts']['leave'],
                    $row['counts']['half_day'],
                    $row['counts']['not_marked'],
                ], $row['daily_codes']));
            }

            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Download monthly attendance report with date-wise status codes (P/A/L/HD/NM).
     */
    public function downloadMonthlyAttendanceReport(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'student_id' => 'nullable|string|max:100',
            'student_ids' => 'nullable|string|max:1000',
            'month' => 'required|integer|min:1|max:12',
            'academic_year_id' => 'required|exists:academic_years,id',
        ]);

        $studentIds = $this->resolveStudentIds($validated);
        if (empty($studentIds)) {
            abort(422, 'At least one valid student_id is required.');
        }

        $session = AcademicYear::findOrFail((int) $validated['academic_year_id']);
        $resolvedYear = $this->resolveYearForMonthInSession($session, (int) $validated['month']);
        if (!$resolvedYear) {
            abort(422, 'Selected month is outside the selected session range.');
        }

        $monthStart = Carbon::createFromDate((int) $resolvedYear, (int) $validated['month'], 1)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $daysInMonth = $monthStart->daysInMonth;

        $students = Student::query()
            ->with([
                'user',
                'enrollments' => function ($q) use ($validated, $monthStart, $monthEnd) {
                    $q->with([
                        'academicYear',
                        'classModel',
                        'section',
                        'attendances' => function ($attendanceQuery) use ($monthStart, $monthEnd) {
                            $attendanceQuery->whereBetween('date', [
                                $monthStart->toDateString(),
                                $monthEnd->toDateString(),
                            ])->orderBy('date');
                        },
                    ]);

                    if (!empty($validated['academic_year_id'])) {
                        $q->where('academic_year_id', (int) $validated['academic_year_id']);
                    } else {
                        $q->whereHas('academicYear', function ($yearQuery) use ($monthStart, $monthEnd) {
                            $yearQuery
                                ->whereDate('start_date', '<=', $monthEnd->toDateString())
                                ->whereDate('end_date', '>=', $monthStart->toDateString());
                        });
                    }
                },
            ])
            ->whereIn('id', $studentIds)
            ->orderBy('id')
            ->get();

        $dayHeaders = range(1, $daysInMonth);
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="attendance_monthly_' . now()->format('Ymd_His') . '.csv"',
        ];

        $callback = function () use ($students, $monthStart, $validated, $dayHeaders, $daysInMonth) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_merge([
                'student_id',
                'admission_number',
                'student_name',
                'class',
                'section',
                'session',
                'month',
                'year',
            ], $dayHeaders));

            foreach ($students as $student) {
                $enrollments = $student->enrollments;

                if ($enrollments->isEmpty()) {
                    $dailyCodes = array_fill(0, $daysInMonth, 'NM');
                    fputcsv($out, array_merge([
                        $student->id,
                        $student->admission_number,
                        $student->full_name,
                        '',
                        '',
                        '',
                        $monthStart->format('F'),
                        (int) $resolvedYear,
                    ], $dailyCodes));
                    continue;
                }

                foreach ($enrollments as $enrollment) {
                    $dailyCodes = array_fill(1, $daysInMonth, 'NM');
                    foreach ($enrollment->attendances as $attendance) {
                        $day = (int) Carbon::parse($attendance->date)->format('j');
                        if ($day >= 1 && $day <= $daysInMonth) {
                            $dailyCodes[$day] = $this->attendanceStatusCode($attendance->status);
                        }
                    }

                    fputcsv($out, array_merge([
                        $student->id,
                        $student->admission_number,
                        $student->full_name,
                        $enrollment->classModel?->name ?? '',
                        $enrollment->section?->name ?? '',
                        $enrollment->academicYear?->name ?? '',
                        $monthStart->format('F'),
                        (int) $resolvedYear,
                    ], array_values($dailyCodes)));
                }
            }

            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Download session-wise attendance history (month-by-month) for one or multiple students.
     */
    public function downloadSessionWiseAttendanceReport(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'student_id' => 'nullable|string|max:100',
            'student_ids' => 'nullable|string|max:1000',
            'academic_year_id' => 'nullable|exists:academic_years,id',
        ]);

        $studentIds = $this->resolveStudentIds($validated);
        if (empty($studentIds)) {
            abort(422, 'At least one valid student_id is required.');
        }

        $students = Student::query()
            ->with([
                'user',
                'enrollments' => function ($q) use ($validated) {
                    $q->with([
                        'academicYear',
                        'classModel',
                        'section',
                        'attendances' => function ($attendanceQuery) {
                            $attendanceQuery->orderBy('date');
                        },
                    ])->orderBy('academic_year_id');

                    if (!empty($validated['academic_year_id'])) {
                        $q->where('academic_year_id', (int) $validated['academic_year_id']);
                    }
                },
            ])
            ->whereIn('id', $studentIds)
            ->orderBy('id')
            ->get();

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="attendance_session_wise_' . now()->format('Ymd_His') . '.csv"',
        ];

        $callback = function () use ($students) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'student_id',
                'admission_number',
                'student_name',
                'session',
                'class',
                'section',
                'month',
                'total_days_marked',
                'present',
                'absent',
                'leave',
                'half_day',
                'not_marked',
                'attendance_percentage',
            ]);

            foreach ($students as $student) {
                if ($student->enrollments->isEmpty()) {
                    fputcsv($out, [
                        $student->id,
                        $student->admission_number,
                        $student->full_name,
                        '',
                        '',
                        '',
                        '',
                        0,
                        0,
                        0,
                        0,
                        0,
                        0,
                        0,
                    ]);
                    continue;
                }

                foreach ($student->enrollments as $enrollment) {
                    $yearStart = $enrollment->academicYear?->start_date
                        ? Carbon::parse($enrollment->academicYear->start_date)->startOfMonth()
                        : null;
                    $yearEnd = $enrollment->academicYear?->end_date
                        ? Carbon::parse($enrollment->academicYear->end_date)->startOfMonth()
                        : null;

                    if (!$yearStart || !$yearEnd) {
                        $firstAttendance = $enrollment->attendances->min('date');
                        $lastAttendance = $enrollment->attendances->max('date');
                        $yearStart = $firstAttendance ? Carbon::parse($firstAttendance)->startOfMonth() : now()->startOfMonth();
                        $yearEnd = $lastAttendance ? Carbon::parse($lastAttendance)->startOfMonth() : $yearStart->copy();
                    }

                    $cursor = $yearStart->copy();
                    while ($cursor->lessThanOrEqualTo($yearEnd)) {
                        $monthAttendances = $enrollment->attendances->filter(function ($attendance) use ($cursor) {
                            $date = Carbon::parse($attendance->date);
                            return $date->year === $cursor->year && $date->month === $cursor->month;
                        });

                        $total = $monthAttendances->count();
                        $present = $monthAttendances->where('status', 'present')->count();
                        $absent = $monthAttendances->where('status', 'absent')->count();
                        $leave = $monthAttendances->where('status', 'leave')->count();
                        $halfDay = $monthAttendances->where('status', 'half_day')->count();
                        $percentage = $total > 0 ? round((($present + $halfDay) / $total) * 100, 2) : 0;

                        fputcsv($out, [
                            $student->id,
                            $student->admission_number,
                            $student->full_name,
                            $enrollment->academicYear?->name ?? '',
                            $enrollment->classModel?->name ?? '',
                            $enrollment->section?->name ?? '',
                            $cursor->format('F Y'),
                            $total,
                            $present,
                            $absent,
                            $leave,
                            $halfDay,
                            0,
                            $percentage,
                        ]);

                        $cursor->addMonth();
                    }
                }
            }

            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Lock attendance for a date (prevent further edits)
     */
    public function lockAttendance(Request $request)
    {
        $validated = $request->validate([
            'class_id' => 'nullable|exists:classes,id',
            'section_id' => 'nullable|exists:sections,id',
            'date' => 'required|date',
        ]);
        $scope = $this->resolveAttendanceScope($request, $validated);

        $enrollments = $this->attendanceEnrollmentQuery($scope)
            ->pluck('id');

        Attendance::whereIn('enrollment_id', $enrollments)
            ->where('date', $validated['date'])
            ->update(['is_locked' => true]);

        app(InAppNotificationService::class)->notifyAttendanceLocked($scope, (string) $validated['date']);

        return response()->json([
            'message' => 'Attendance locked successfully'
        ]);
    }

    private function attendanceStatusCode(?string $status): string
    {
        return match ($status) {
            'present' => 'P',
            'absent' => 'A',
            'leave' => 'L',
            'half_day' => 'HD',
            default => 'NM',
        };
    }

    private function resolveStudentIds(array $validated): array
    {
        $tokens = [];

        if (!empty($validated['student_id'])) {
            $tokens[] = (string) $validated['student_id'];
        }

        if (!empty($validated['student_ids'])) {
            $parts = explode(',', (string) $validated['student_ids']);
            foreach ($parts as $part) {
                $tokens[] = trim($part);
            }
        }

        $tokens = collect($tokens)
            ->map(fn ($token) => trim((string) $token))
            ->filter(fn ($token) => $token !== '')
            ->unique()
            ->values();

        if ($tokens->isEmpty()) {
            return [];
        }

        $numericIds = $tokens
            ->filter(fn ($token) => ctype_digit($token))
            ->map(fn ($token) => (int) $token)
            ->values();

        $admissionNumbers = $tokens
            ->filter(fn ($token) => !ctype_digit($token))
            ->values();

        return Student::query()
            ->where(function ($query) use ($numericIds, $admissionNumbers) {
                $seeded = false;
                if ($numericIds->isNotEmpty()) {
                    $query->whereIn('id', $numericIds->all());
                    $seeded = true;
                }

                if ($admissionNumbers->isNotEmpty()) {
                    $method = $seeded ? 'orWhereIn' : 'whereIn';
                    $query->{$method}('admission_number', $admissionNumbers->all());
                }
            })
            ->pluck('id')
            ->unique()
            ->values()
            ->all();
    }

    private function resolveIntegerCsv(?string $raw): array
    {
        if (!$raw) {
            return [];
        }

        return collect(explode(',', $raw))
            ->map(fn ($token) => trim($token))
            ->filter(fn ($token) => $token !== '' && ctype_digit($token))
            ->map(fn ($token) => (int) $token)
            ->unique()
            ->values()
            ->all();
    }

    private function buildBulkMonthlyRows(Collection $enrollments, int $daysInMonth, Carbon $monthStart, int $year): Collection
    {
        return $enrollments->map(function (Enrollment $enrollment) use ($daysInMonth, $monthStart, $year) {
            $dailyCodes = array_fill(1, $daysInMonth, 'NM');
            foreach ($enrollment->attendances as $attendance) {
                $day = (int) Carbon::parse($attendance->date)->format('j');
                if ($day >= 1 && $day <= $daysInMonth) {
                    $dailyCodes[$day] = $this->attendanceStatusCode($attendance->status);
                }
            }

            $codes = array_values($dailyCodes);
            $counts = [
                'present' => collect($codes)->where(fn ($code) => $code === 'P')->count(),
                'absent' => collect($codes)->where(fn ($code) => $code === 'A')->count(),
                'leave' => collect($codes)->where(fn ($code) => $code === 'L')->count(),
                'half_day' => collect($codes)->where(fn ($code) => $code === 'HD')->count(),
                'not_marked' => collect($codes)->where(fn ($code) => $code === 'NM')->count(),
            ];

            return [
                'enrollment_id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'admission_number' => $enrollment->student?->admission_number,
                'student_name' => $enrollment->student?->full_name,
                'class' => $enrollment->classModel?->name ?? '',
                'section' => $enrollment->section?->name ?? '',
                'session' => $enrollment->academicYear?->name ?? '',
                'month' => $monthStart->format('F'),
                'year' => $year,
                'counts' => $counts,
                'daily_codes' => $codes,
            ];
        });
    }

    private function resolveYearForMonthInSession(AcademicYear $session, int $month): ?int
    {
        if (!$session->start_date || !$session->end_date) {
            return null;
        }

        $cursor = Carbon::parse($session->start_date)->startOfMonth();
        $end = Carbon::parse($session->end_date)->startOfMonth();

        while ($cursor->lessThanOrEqualTo($end)) {
            if ((int) $cursor->month === (int) $month) {
                return (int) $cursor->year;
            }
            $cursor->addMonth();
        }

        return null;
    }

    private function resolveAttendanceScope(Request $request, array $validated): array
    {
        $classId = !empty($validated['class_id']) ? (int) $validated['class_id'] : null;
        $sectionId = !empty($validated['section_id']) ? (int) $validated['section_id'] : null;

        if (!$classId && !$sectionId) {
            abort(422, 'Select at least a class.');
        }

        if ($sectionId) {
            $section = Section::query()->findOrFail($sectionId);
            if ($classId && (int) $section->class_id !== $classId) {
                abort(422, 'Selected section does not belong to the selected class.');
            }
            $classId ??= (int) $section->class_id;
        }

        $this->ensureTeacherAttendanceAccess($request, $sectionId);

        return [
            'class_id' => $classId,
            'section_id' => $sectionId,
        ];
    }

    private function attendanceEnrollmentQuery(array $scope)
    {
        $query = Enrollment::query()->where('class_id', (int) $scope['class_id']);

        if (!empty($scope['section_id'])) {
            $query->where('section_id', (int) $scope['section_id']);
        }

        return $query;
    }

    private function ensureTeacherAttendanceAccess(Request $request, ?int $sectionId): void
    {
        $user = $request->user();
        if (!$user || !$user->hasRole('teacher')) {
            return;
        }

        if (!$sectionId) {
            abort(403, 'Teachers must select a section for attendance.');
        }

        $isAssigned = DB::table('teacher_subject_assignments')
            ->where('teacher_id', (int) $user->id)
            ->where('section_id', $sectionId)
            ->exists();

        if (!$isAssigned) {
            abort(403, 'This section is not assigned to you.');
        }
    }
}
