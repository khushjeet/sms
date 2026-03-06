<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ResultVerificationLog;
use App\Models\StudentResult;
use Illuminate\Http\Request;

class PublicResultVerificationController extends Controller
{
    private const HIDDEN_RESULT_MESSAGE = 'Your result is not published please contact to the adminstrative/ principal sir';

    public function verify(Request $request)
    {
        $uuid = trim((string) $request->query('v', ''));
        $signature = trim((string) $request->query('sig', ''));

        if ($uuid === '' || $signature === '') {
            $this->logAttempt(null, $uuid !== '' ? $uuid : null, 'missing', 'Missing verification parameters.', $request);

            return response()->json([
                'verified' => false,
                'message' => 'This is not our student/result record.',
            ], 200);
        }

        $result = StudentResult::query()
            ->with([
                'examSession:id,academic_year_id,class_id,name,published_at',
                'examSession.academicYear:id,name',
                'examSession.classModel:id,name',
                'student:id,user_id,admission_number',
                'student.user:id,first_name,last_name',
                'latestVisibility:result_visibility_controls.id,result_visibility_controls.student_result_id,result_visibility_controls.visibility_status,result_visibility_controls.visibility_version',
            ])
            ->where('verification_uuid', $uuid)
            ->first();

        if (!$result) {
            $this->logAttempt(null, $uuid, 'invalid', 'Verification UUID not found.', $request);

            return response()->json([
                'verified' => false,
                'message' => 'This is not our student/result record.',
            ], 200);
        }

        $expected = strtolower(substr((string) $result->verification_hash, 0, 16));
        if (!hash_equals($expected, strtolower($signature))) {
            $this->logAttempt((int) $result->id, $uuid, 'invalid', 'Signature mismatch.', $request);

            return response()->json([
                'verified' => false,
                'message' => 'This is not our student/result record.',
            ], 200);
        }

        if ($result->verification_status === 'revoked') {
            $this->logAttempt((int) $result->id, $uuid, 'revoked', 'Verification is revoked.', $request);

            return response()->json([
                'verified' => false,
                'message' => 'This is not our student/result record.',
            ], 200);
        }

        if ($result->is_superseded) {
            $this->logAttempt((int) $result->id, $uuid, 'superseded', 'Result version is superseded.', $request);

            return response()->json([
                'verified' => false,
                'message' => 'This is not our student/result record.',
            ], 200);
        }

        $visibilityStatus = $result->latestVisibility?->visibility_status ?? 'visible';
        if ($visibilityStatus !== 'visible') {
            $this->logAttempt((int) $result->id, $uuid, 'withheld', self::HIDDEN_RESULT_MESSAGE, $request);

            return response()->json([
                'verified' => false,
                'message' => self::HIDDEN_RESULT_MESSAGE,
            ], 200);
        }

        $studentName = trim(($result->student?->user?->first_name ?? '') . ' ' . ($result->student?->user?->last_name ?? ''));
        $this->logAttempt((int) $result->id, $uuid, 'verified', 'Result verified successfully.', $request);

        return response()->json([
            'verified' => true,
            'message' => 'Verified result.',
            'data' => [
                'student_name' => $studentName,
                'admission_number' => $result->student?->admission_number,
                'class_name' => $result->examSession?->classModel?->name,
                'exam_name' => $result->examSession?->name,
                'academic_year' => $result->examSession?->academicYear?->name,
                'version' => (int) $result->version,
                'issued_at' => $result->published_at?->toDateTimeString(),
                'percentage' => (float) $result->percentage,
                'grade' => $result->grade,
                'result_status' => $result->result_status,
            ],
        ]);
    }

    private function logAttempt(
        ?int $studentResultId,
        ?string $verificationUuid,
        string $status,
        string $message,
        Request $request
    ): void {
        ResultVerificationLog::query()->create([
            'student_result_id' => $studentResultId,
            'verification_uuid' => $verificationUuid,
            'status' => $status,
            'message' => $message,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'verified_at' => now(),
        ]);
    }
}
