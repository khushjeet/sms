<?php

namespace App\Services\Email;

use App\Mail\GenericEventMail;
use App\Jobs\SendTrackedSchoolMessageEmailJob;
use App\Models\AdmitCard;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\SchoolSetting;
use App\Models\Section;
use App\Models\Staff;
use App\Models\Student;
use App\Models\StudentFeeLedger;
use App\Models\StudentResult;
use App\Models\Subject;
use App\Models\User;
use App\Support\SchoolSmtpConfig;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EventNotificationService
{
    private const MAILER = 'school_runtime_smtp';
    private const PASSWORD_PREFIX = 'encrypted:';

    public function notifyStudentRegistered(Student $student, ?string $pdfOutput = null, ?string $filename = null): void
    {
        $student->loadMissing(['user', 'profile', 'parents.user']);

        $this->sendTo(
            $this->studentAndGuardianEmails($student),
            'Student registration completed',
            'Student registration completed successfully.',
            array_filter([
                'Student: ' . $this->studentName($student),
                'Admission No: ' . ($student->admission_number ?: '-'),
                'Admission Date: ' . optional($student->admission_date)->format('d M Y'),
            ]),
            $this->attachmentPayload($pdfOutput, $filename)
        );
    }

    public function notifyStudentUpdated(Student $student, array $changes): void
    {
        $student->loadMissing(['user', 'profile', 'parents.user']);

        $this->sendTo(
            $this->studentAndGuardianEmails($student),
            'Student profile updated',
            'Student details were updated successfully.',
            array_merge([
                'Student: ' . $this->studentName($student),
                'Admission No: ' . ($student->admission_number ?: '-'),
                'Updated fields:',
            ], $changes)
        );
    }

    public function notifyEmployeeProfileCreated(Staff $staff): void
    {
        $staff->loadMissing('user');

        $this->sendTo(
            [$staff->user?->email],
            'Employee profile created',
            'Your employee profile has been created successfully.',
            array_filter([
                'Employee: ' . $this->employeeName($staff),
                'Employee ID: ' . ($staff->employee_id ?: '-'),
                'Role: ' . strtoupper((string) ($staff->user?->role ?: '-')),
                'Designation: ' . ($staff->designation ?: '-'),
                'Department: ' . ($staff->department ?: '-'),
                'Joining Date: ' . optional($staff->joining_date)->format('d M Y'),
            ])
        );
    }

    public function notifyEmployeeProfileUpdated(Staff $staff, array $changes = []): void
    {
        $staff->loadMissing('user');

        $this->sendTo(
            [$staff->user?->email],
            'Employee profile updated',
            'Your employee profile details were updated.',
            array_merge(
                array_filter([
                    'Employee: ' . $this->employeeName($staff),
                    'Employee ID: ' . ($staff->employee_id ?: '-'),
                ]),
                $changes === []
                    ? ['Profile details were refreshed in the system.']
                    : array_merge(['Updated fields:'], $changes)
            )
        );
    }

    public function notifyStudentPdfShared(Student $student, string $pdfOutput, string $filename): void
    {
        $student->loadMissing(['user', 'profile', 'parents.user']);

        $this->sendTo(
            $this->studentAndGuardianEmails($student),
            'Student profile PDF shared',
            'The latest student profile PDF has been shared.',
            [
                'Student: ' . $this->studentName($student),
                'Admission No: ' . ($student->admission_number ?: '-'),
                'The profile PDF is attached to this email.',
            ],
            $this->attachmentPayload($pdfOutput, $filename)
        );
    }

    public function notifyEnrollmentEvent(Enrollment $enrollment, string $actionLabel): void
    {
        $enrollment->loadMissing(['student.user', 'student.profile', 'student.parents.user', 'academicYear', 'classModel', 'section.class']);

        $className = $enrollment->section?->class?->name ?? $enrollment->classModel?->name ?? '-';
        $sectionName = $enrollment->section?->name ?: 'Not assigned';

        $this->sendTo(
            $this->studentAndGuardianEmails($enrollment->student),
            'Enrollment update',
            $actionLabel,
            array_filter([
                'Student: ' . $this->studentName($enrollment->student),
                'Academic Year: ' . ($enrollment->academicYear?->name ?: '-'),
                'Class: ' . $className,
                'Section: ' . $sectionName,
                'Roll Number: ' . ($enrollment->roll_number ?: '-'),
                $enrollment->remarks ? 'Remarks: ' . $enrollment->remarks : null,
            ])
        );
    }

    public function notifyTimetableUpdated(Section $section, int $academicYearId, array $teacherIds = []): void
    {
        $section->loadMissing('class');

        $studentEmails = DB::table('enrollments as e')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('e.section_id', $section->id)
            ->where('e.academic_year_id', $academicYearId)
            ->where('e.status', 'active')
            ->pluck('u.email')
            ->all();

        $teacherEmails = empty($teacherIds)
            ? []
            : User::query()->whereIn('id', $teacherIds)->pluck('email')->all();

        $this->sendTo(
            array_merge($studentEmails, $teacherEmails),
            'Timetable updated',
            'The timetable has been updated.',
            array_filter([
                'Class: ' . ($section->class?->name ?: '-'),
                'Section: ' . $section->name,
                'Academic Year ID: ' . $academicYearId,
            ])
        );
    }

    public function notifyTeacherSubjectAssigned(
        Subject $subject,
        array $teacherIds,
        int $classId,
        ?int $sectionId,
        int $academicYearId
    ): void {
        if (empty($teacherIds)) {
            return;
        }

        $className = (string) (DB::table('classes')->where('id', $classId)->value('name') ?: 'Class #' . $classId);
        $sectionName = $sectionId ? (string) (DB::table('sections')->where('id', $sectionId)->value('name') ?: 'Section #' . $sectionId) : 'All sections';

        $emails = User::query()->whereIn('id', $teacherIds)->pluck('email')->all();

        $this->sendTo(
            $emails,
            'Subject assigned',
            'A subject has been assigned to you.',
            [
                'Subject: ' . $subject->name,
                'Subject Code: ' . ($subject->subject_code ?: $subject->code ?: '-'),
                'Class: ' . $className,
                'Section: ' . $sectionName,
                'Academic Year ID: ' . $academicYearId,
            ]
        );
    }

    public function notifyPaymentRecorded(Payment $payment): void
    {
        $payment->loadMissing(['enrollment.student.user', 'enrollment.student.profile', 'enrollment.student.parents.user', 'enrollment.section.class', 'enrollment.classModel']);
        $enrollment = $payment->enrollment;
        if (!$enrollment?->student) {
            return;
        }

        $className = $enrollment->section?->class?->name ?? $enrollment->classModel?->name ?? '-';
        $sectionName = $enrollment->section?->name ?: 'Not assigned';

        $this->sendTo(
            $this->studentAndGuardianEmails($enrollment->student),
            'Fee payment received',
            'Your payment has been recorded successfully.',
            array_filter([
                'Student: ' . $this->studentName($enrollment->student),
                'Receipt No: ' . ($payment->receipt_number ?: '-'),
                'Amount: ' . number_format((float) $payment->amount, 2),
                'Payment Method: ' . strtoupper((string) ($payment->payment_method ?: 'cash')),
                'Payment Date: ' . optional($payment->payment_date)->format('d M Y'),
                'Class: ' . $className,
                'Section: ' . $sectionName,
                $payment->remarks ? 'Remarks: ' . $payment->remarks : null,
            ])
        );
    }

    public function notifyStudentLedgerRecorded(
        StudentFeeLedger $ledger,
        ?string $subject = null,
        ?string $headline = null,
        array $extraLines = []
    ): void {
        $ledger->loadMissing([
            'enrollment.student.user',
            'enrollment.student.profile',
            'enrollment.student.parents.user',
            'enrollment.section.class',
            'enrollment.classModel',
            'enrollment.academicYear',
        ]);

        $enrollment = $ledger->enrollment;
        if (!$enrollment?->student) {
            return;
        }

        $transactionLabel = $ledger->transaction_type === 'credit' ? 'Credit' : 'Debit';
        $className = $enrollment->section?->class?->name ?? $enrollment->classModel?->name ?? '-';
        $sectionName = $enrollment->section?->name ?: 'Not assigned';

        $this->sendTo(
            $this->studentAndGuardianEmails($enrollment->student),
            $subject ?: 'Student account updated',
            $headline ?: sprintf('%s entry posted to the student account.', $transactionLabel),
            array_merge(
                array_filter([
                    'Student: ' . $this->studentName($enrollment->student),
                    'Admission No: ' . ($enrollment->student->admission_number ?: '-'),
                    'Entry Type: ' . $transactionLabel,
                    'Amount: ' . number_format((float) $ledger->amount, 2),
                    'Reference: ' . $this->ledgerReferenceLabel($ledger),
                    'Posted On: ' . optional($ledger->posted_at)->format('d M Y h:i A'),
                    'Academic Year: ' . ($enrollment->academicYear?->name ?: '-'),
                    'Class: ' . $className,
                    'Section: ' . $sectionName,
                    $ledger->narration ? 'Remarks: ' . $ledger->narration : null,
                    $ledger->is_reversal ? 'This entry is marked as a reversal.' : null,
                ]),
                $extraLines
            )
        );
    }

    public function notifyAdmitPublished(AdmitCard $admitCard): void
    {
        $admitCard->loadMissing(['student.user', 'student.profile', 'student.parents.user', 'examSession.classModel', 'examSession.academicYear']);
        if (!$admitCard->student) {
            return;
        }

        $this->sendTo(
            $this->studentAndGuardianEmails($admitCard->student),
            'Admit card published',
            'Your admit card has been published.',
            array_filter([
                'Student: ' . $this->studentName($admitCard->student),
                'Exam: ' . ($admitCard->examSession?->name ?: '-'),
                'Class: ' . ($admitCard->examSession?->classModel?->name ?: '-'),
                'Academic Year: ' . ($admitCard->examSession?->academicYear?->name ?: '-'),
                'Roll Number: ' . ($admitCard->roll_number ?: '-'),
                'Seat Number: ' . ($admitCard->seat_number ?: '-'),
            ])
        );
    }

    public function notifyResultPublished(StudentResult $result): void
    {
        $result->loadMissing(['student.user', 'student.profile', 'student.parents.user', 'examSession.classModel', 'examSession.academicYear']);
        if (!$result->student) {
            return;
        }

        $this->sendTo(
            $this->studentAndGuardianEmails($result->student),
            'Result published',
            'Your result has been published.',
            array_filter([
                'Student: ' . $this->studentName($result->student),
                'Exam: ' . ($result->examSession?->name ?: '-'),
                'Class: ' . ($result->examSession?->classModel?->name ?: '-'),
                'Academic Year: ' . ($result->examSession?->academicYear?->name ?: '-'),
                'Percentage: ' . number_format((float) $result->percentage, 2) . '%',
                'Result Status: ' . strtoupper((string) ($result->result_status ?: '-')),
                'Grade: ' . ($result->grade ?: '-'),
            ])
        );
    }

    public function notifyResultPublishedByIds(array $resultIds): void
    {
        if (empty($resultIds)) {
            return;
        }

        StudentResult::query()
            ->whereIn('id', $resultIds)
            ->get()
            ->each(fn (StudentResult $result) => $this->notifyResultPublished($result));
    }

    public function notifyAdmitPublishedByIds(array $admitCardIds): void
    {
        if (empty($admitCardIds)) {
            return;
        }

        AdmitCard::query()
            ->whereIn('id', $admitCardIds)
            ->get()
            ->each(fn (AdmitCard $admitCard) => $this->notifyAdmitPublished($admitCard));
    }

    public function canSendEmail(): bool
    {
        return $this->isConfigured($this->settings());
    }

    public function sendCustomStudentMessage(
        Collection|array $students,
        string $audience,
        string $subject,
        string $message
    ): array {
        $studentCollection = $students instanceof Collection ? $students : collect($students);
        $normalizedSubject = trim($subject) !== '' ? trim($subject) : 'School message';
        $lines = $this->normalizeMessageLines($message);
        $recipients = $this->resolveCustomStudentRecipients($studentCollection, $audience);
        if ($recipients === []) {
            return [
                'batch_id' => null,
                'students_count' => $studentCollection->count(),
                'recipient_count' => 0,
                'queued_count' => 0,
                'delivered_count' => 0,
                'failed_count' => 0,
            ];
        }

        $mailConfig = $this->runtimeMailConfig();
        $jobs = collect($recipients)
            ->map(fn (string $email) => new SendTrackedSchoolMessageEmailJob(
                $email,
                $normalizedSubject,
                'You have received a new school message.',
                $lines !== [] ? $lines : ['School administration shared a new message.'],
                $mailConfig
            ))
            ->all();

        /** @var Batch $batch */
        $batch = Bus::batch($jobs)
            ->allowFailures()
            ->name('message-center:' . Str::limit($normalizedSubject, 40, ''))
            ->onQueue('emails')
            ->dispatch();

        return [
            'batch_id' => $batch->id,
            'students_count' => $studentCollection->count(),
            'recipient_count' => count($recipients),
            'queued_count' => $batch->pendingJobs,
            'delivered_count' => max(0, $batch->totalJobs - $batch->pendingJobs - $batch->failedJobs),
            'failed_count' => $batch->failedJobs,
        ];
    }

    public function batchStats(string $batchId): ?array
    {
        $batch = Bus::findBatch($batchId);
        if ($batch === null) {
            return null;
        }

        $queued = max(0, $batch->pendingJobs - $batch->failedJobs);
        $processed = max(0, $batch->totalJobs - $batch->pendingJobs);
        $delivered = max(0, $processed - $batch->failedJobs);

        return [
            'batch_id' => $batch->id,
            'total_count' => $batch->totalJobs,
            'queued_count' => $queued,
            'delivered_count' => $delivered,
            'failed_count' => $batch->failedJobs,
            'finished' => $batch->finished(),
            'cancelled' => $batch->cancelled(),
        ];
    }

    private function sendTo(array $recipients, string $subject, string $headline, array $lines, ?array $attachment = null): void
    {
        $mailer = $this->configureMailer();
        if ($mailer === null) {
            return;
        }

        $uniqueRecipients = collect($recipients)
            ->filter(fn ($email) => $this->isDeliverableEmail($email))
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->unique()
            ->values();

        if ($uniqueRecipients->isEmpty()) {
            return;
        }

        $settings = $this->settings();
        $schoolName = SchoolSetting::getValue('school_name', config('school.name', config('app.name')));

        foreach ($uniqueRecipients as $email) {
            try {
                $mail = new GenericEventMail($subject, $headline, $lines, $schoolName);
                if ($attachment !== null) {
                    $mail->attachData(
                        $attachment['data'],
                        $attachment['name'],
                        ['mime' => $attachment['mime']]
                    );
                }

                $replyToAddress = trim((string) ($settings['smtp_reply_to_address'] ?? ''));
                if ($replyToAddress !== '') {
                    $mail->replyTo(
                        $replyToAddress,
                        trim((string) ($settings['smtp_reply_to_name'] ?? '')) ?: null
                    );
                }

                $mail->afterCommit()->onQueue('emails');

                Mail::mailer($mailer)->to($email)->queue($mail);
            } catch (\Throwable $exception) {
                report($exception);
                Log::warning('Email notification failed.', [
                    'recipient' => $email,
                    'subject' => $subject,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function configureMailer(): ?string
    {
        $settings = $this->settings();
        if (!$this->isConfigured($settings)) {
            return null;
        }

        $mailerConfig = SchoolSmtpConfig::buildMailerConfig(
            $settings,
            $this->decodePassword($settings['smtp_password'] ?? null),
            trim((string) ($settings['smtp_from_name'] ?? '')) ?: SchoolSetting::getValue('school_name', config('app.name')),
            parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'
        );

        if ($mailerConfig === null) {
            return null;
        }

        config([
            'mail.mailers.' . self::MAILER => $mailerConfig,
            'mail.from.address' => $settings['smtp_from_address'],
            'mail.from.name' => $settings['smtp_from_name'] ?: SchoolSetting::getValue('school_name', config('app.name')),
        ]);

        app('mail.manager')->purge(self::MAILER);

        return self::MAILER;
    }

    private function isConfigured(array $settings): bool
    {
        return ($settings['smtp_enabled'] ?? '0') === '1'
            && trim((string) ($settings['smtp_host'] ?? '')) !== ''
            && trim((string) ($settings['smtp_port'] ?? '')) !== ''
            && trim((string) ($settings['smtp_from_address'] ?? '')) !== '';
    }

    public function runtimeMailConfig(): array
    {
        $settings = $this->settings();

        $encryption = SchoolSmtpConfig::normalizeEncryption($settings['smtp_encryption'] ?? null);
        $mailerConfig = SchoolSmtpConfig::buildMailerConfig(
            $settings,
            $this->decodePassword($settings['smtp_password'] ?? null),
            trim((string) ($settings['smtp_from_name'] ?? '')) ?: SchoolSetting::getValue('school_name', config('app.name')),
            parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'
        );

        return [
            'mailer' => self::MAILER,
            'host' => (string) ($mailerConfig['host'] ?? ''),
            'port' => (int) ($mailerConfig['port'] ?? 0),
            'username' => $mailerConfig['username'] ?? null,
            'password' => $mailerConfig['password'] ?? null,
            'scheme' => $mailerConfig['scheme'] ?? 'smtp',
            'auto_tls' => (bool) ($mailerConfig['auto_tls'] ?? false),
            'encryption' => $encryption,
            'from_address' => (string) ($settings['smtp_from_address'] ?? ''),
            'from_name' => trim((string) ($settings['smtp_from_name'] ?? '')) ?: SchoolSetting::getValue('school_name', config('app.name')),
            'reply_to_address' => trim((string) ($settings['smtp_reply_to_address'] ?? '')) ?: null,
            'reply_to_name' => trim((string) ($settings['smtp_reply_to_name'] ?? '')) ?: null,
            'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost',
            'school_name' => SchoolSetting::getValue('school_name', config('school.name', config('app.name'))),
        ];
    }

    private function settings(): array
    {
        return SchoolSetting::getValues([
            'smtp_enabled',
            'smtp_host',
            'smtp_port',
            'smtp_username',
            'smtp_password',
            'smtp_encryption',
            'smtp_from_address',
            'smtp_from_name',
            'smtp_reply_to_address',
            'smtp_reply_to_name',
        ]);
    }

    private function studentAndGuardianEmails(Student $student): array
    {
        $student->loadMissing(['user', 'profile', 'parents.user']);

        return array_filter([
            $student->user?->email,
            $student->profile?->father_email,
            $student->profile?->mother_email,
            ...$student->parents->map(fn ($parent) => $parent->user?->email)->all(),
        ]);
    }

    private function resolveCustomStudentRecipients(Collection $students, string $audience): array
    {
        $normalizedAudience = in_array($audience, ['students', 'parents', 'both'], true)
            ? $audience
            : 'both';

        return $students
            ->flatMap(function (Student $student) use ($normalizedAudience) {
                $student->loadMissing(['user', 'profile', 'parents.user']);

                $studentEmails = [$student->user?->email];
                $parentEmails = [
                    $student->profile?->father_email,
                    $student->profile?->mother_email,
                    ...$student->parents->map(fn ($parent) => $parent->user?->email)->all(),
                ];

                return match ($normalizedAudience) {
                    'students' => $studentEmails,
                    'parents' => $parentEmails,
                    default => array_merge($studentEmails, $parentEmails),
                };
            })
            ->filter(fn ($email) => $this->isDeliverableEmail($email))
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeMessageLines(string $message): array
    {
        return collect(preg_split("/\r\n|\n|\r/", $message) ?: [])
            ->map(fn ($line) => trim((string) $line))
            ->filter(fn ($line) => $line !== '')
            ->values()
            ->all();
    }

    private function studentName(?Student $student): string
    {
        return trim((string) ($student?->user?->full_name ?? $student?->full_name ?? 'Student'));
    }

    private function employeeName(?Staff $staff): string
    {
        return trim((string) ($staff?->user?->full_name ?? 'Employee'));
    }

    private function ledgerReferenceLabel(StudentFeeLedger $ledger): string
    {
        return match ((string) $ledger->reference_type) {
            'payment' => 'Fee payment',
            'refund' => 'Payment refund',
            'discount' => 'Fee discount',
            'fee_assignment' => 'Fee assignment',
            'fee_installment' => 'Installment charge',
            'transport' => 'Transport charge',
            'special_fee' => 'Special fee',
            'manual' => 'Manual adjustment',
            default => Str::headline((string) $ledger->reference_type ?: 'account entry'),
        };
    }

    private function isDeliverableEmail(mixed $email): bool
    {
        $normalized = strtolower(trim((string) $email));

        return $normalized !== ''
            && filter_var($normalized, FILTER_VALIDATE_EMAIL) !== false
            && !Str::endsWith($normalized, '@placeholder.local');
    }

    private function decodePassword(?string $value): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        if (!Str::startsWith($normalized, self::PASSWORD_PREFIX)) {
            return $normalized;
        }

        try {
            return Crypt::decryptString(Str::after($normalized, self::PASSWORD_PREFIX));
        } catch (\Throwable) {
            return null;
        }
    }

    private function attachmentPayload(?string $data, ?string $filename): ?array
    {
        if ($data === null || $data === '' || $filename === null || trim($filename) === '') {
            return null;
        }

        return [
            'data' => $data,
            'name' => trim($filename),
            'mime' => 'application/pdf',
        ];
    }
}
