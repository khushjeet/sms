<?php

namespace App\Services;

use App\Models\SchoolSetting;
use App\Models\Student;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class StudentPdfService
{
    public function output(Student $student): string
    {
        $student = $this->loadStudent($student);

        $payload = [
            'student' => $student,
            'school' => $this->studentPdfSchoolDetails(),
            'pdf' => $this->buildStudentPdfPayload($student),
            'generated_on' => now()->format('d M Y'),
        ];

        $pdf = Pdf::loadView('students.profile-pdf', $payload)->setPaper('a4', 'portrait');
        $pdf->setOption(['isRemoteEnabled' => true]);

        return $pdf->output();
    }

    public function filename(Student $student): string
    {
        return 'student-' . preg_replace('/\s+/', '-', (string) ($student->admission_number ?: $student->id)) . '.pdf';
    }

    public function loadStudent(Student $student): Student
    {
        $student->loadMissing([
            'user',
            'parents.user',
            'profile.academicYear',
            'profile.class',
            'currentEnrollment.section.class',
            'currentEnrollment.classModel',
        ]);

        return $student;
    }

    private function studentPdfSchoolDetails(): array
    {
        $schoolName = SchoolSetting::getValue('school_name', config('school.name', 'INDIAN PUBLIC SCHOOL'));
        $schoolLogo = SchoolSetting::getValue('school_logo_url', config('school.logo_url'));
        $watermarkLogo = SchoolSetting::getValue('school_watermark_logo_url');

        return [
            'name' => $schoolName,
            'address' => SchoolSetting::getValue('school_address', config('school.address', '')),
            'phone' => SchoolSetting::getValue('school_phone', config('school.phone', '')),
            'email' => config('school.email', ''),
            'website' => SchoolSetting::getValue('school_website', config('school.website', '')),
            'udise' => SchoolSetting::getValue('school_udise_code', config('school.udise', '')),
            'reg_no' => SchoolSetting::getValue('school_registration_number', config('school.reg_no', '')),
            'logo' => $this->resolvePdfAssetPath((string) ($schoolLogo ?? 'storage/assets/ips.png')),
            'logo_data_url' => $this->buildPdfImageDataUrl((string) ($schoolLogo ?? '')),
            'watermark_text' => SchoolSetting::getValue('school_watermark_text', $schoolName),
            'watermark_logo' => $this->resolvePdfAssetPath((string) ($watermarkLogo ?: $schoolLogo ?: '')),
            'watermark_logo_data_url' => $this->buildPdfImageDataUrl((string) ($watermarkLogo ?: $schoolLogo ?: '')),
        ];
    }

    private function buildStudentPdfPayload(Student $student): array
    {
        $studentName = trim((string) ($student->user?->full_name ?: trim(($student->user?->first_name ?? '') . ' ' . ($student->user?->last_name ?? ''))));
        $profile = $student->profile;
        $currentEnrollment = $student->currentEnrollment;
        $className = $currentEnrollment?->section?->class?->name
            ?? $currentEnrollment?->classModel?->name
            ?? $profile?->class?->name
            ?? '-';

        $photo = $profile?->avatar_url
            ?? $student->avatar_url
            ?? $student->user?->avatar;

        return [
            'student_name' => $studentName !== '' ? $studentName : '-',
            'admission_number' => $student->admission_number ?: '-',
            'class_name' => $className,
            'roll_number' => $profile?->roll_number ?: '-',
            'dob' => $this->formatPdfDate($student->date_of_birth),
            'gender' => $student->gender ?: '-',
            'blood_group' => $student->blood_group ?: '-',
            'father_name' => $profile?->father_name ?: '-',
            'father_phone' => $profile?->father_mobile_number ?: $profile?->father_mobile ?: '-',
            'father_email' => $profile?->father_email ?: '-',
            'father_occupation' => $profile?->father_occupation ?: '-',
            'mother_name' => $profile?->mother_name ?: '-',
            'mother_phone' => $profile?->mother_mobile_number ?: $profile?->mother_mobile ?: '-',
            'mother_email' => $profile?->mother_email ?: '-',
            'mother_occupation' => $profile?->mother_occupation ?: '-',
            'permanent_address' => $profile?->permanent_address ?: ($student->address ?: '-'),
            'current_address' => $profile?->current_address ?: $this->buildCurrentAddress($student),
            'account_number' => $profile?->bank_account_number ?: '-',
            'account_holder' => $profile?->bank_account_holder ?: ($profile?->father_name ?: '-'),
            'ifsc' => $profile?->ifsc_code ?: '-',
            'relation_with_account_holder' => $profile?->relation_with_account_holder ?: '-',
            'photo' => $this->resolvePdfAssetPath((string) $photo),
            'principal_signature' => $this->resolvePdfAssetPath((string) (SchoolSetting::getValue('principal_signature_path') ?? '')),
            'director_signature' => $this->resolvePdfAssetPath((string) (SchoolSetting::getValue('director_signature_path') ?? '')),
        ];
    }

    private function buildCurrentAddress(Student $student): string
    {
        $parts = array_filter([
            $student->address,
            $student->city,
            $student->state,
            $student->pincode,
        ], fn ($value) => filled($value));

        return !empty($parts) ? implode(', ', $parts) : '-';
    }

    private function formatPdfDate(mixed $value): string
    {
        if (blank($value)) {
            return '-';
        }

        try {
            return Carbon::parse($value)->format('d M Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function resolvePdfAssetPath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'data:') || str_starts_with($path, 'file:')) {
            return $path;
        }

        if (preg_match('/^https?:/i', $path) === 1) {
            $parsedPath = parse_url($path, PHP_URL_PATH);
            $parsedPath = is_string($parsedPath) ? $parsedPath : '';

            if ($parsedPath !== '') {
                $localResolved = $this->resolvePdfAssetPath($parsedPath);
                if ($localResolved) {
                    return $localResolved;
                }
            }

            return $path;
        }

        $normalized = ltrim(str_replace('\\', '/', $path), '/');
        $publicPath = public_path($normalized);
        if (is_file($publicPath)) {
            return $publicPath;
        }

        if (str_starts_with($normalized, 'public/')) {
            $publicAliasPath = public_path(substr($normalized, 7));
            if (is_file($publicAliasPath)) {
                return $publicAliasPath;
            }
        }

        $storageRelative = preg_replace('/^(public\/storage\/|storage\/)/', '', $normalized);
        $storageRelative = is_string($storageRelative) ? $storageRelative : '';
        if ($storageRelative !== '' && Storage::disk('public')->exists($storageRelative)) {
            return Storage::disk('public')->path($storageRelative);
        }

        return null;
    }

    private function buildPdfImageDataUrl(string $path): ?string
    {
        $resolved = $this->resolvePdfAssetPath($path);
        if (!$resolved || preg_match('/^data:/i', $resolved) === 1) {
            return $resolved ?: null;
        }

        if (preg_match('/^https?:/i', $resolved) === 1) {
            $parsed = parse_url($resolved);
            $host = strtolower((string) ($parsed['host'] ?? ''));
            $localPath = (string) ($parsed['path'] ?? '');
            if (!in_array($host, ['127.0.0.1', 'localhost'], true) || $localPath === '') {
                return null;
            }
            $resolved = public_path(ltrim($localPath, '/'));
        }

        if (!is_file($resolved) || !is_readable($resolved)) {
            return null;
        }

        $mime = mime_content_type($resolved) ?: null;
        if ($mime === null || !str_starts_with($mime, 'image/')) {
            return null;
        }

        $contents = @file_get_contents($resolved);
        if ($contents === false) {
            return null;
        }

        return 'data:' . $mime . ';base64,' . base64_encode($contents);
    }
}
