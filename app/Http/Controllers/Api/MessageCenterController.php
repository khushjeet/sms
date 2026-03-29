<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScheduledMessage;
use App\Models\SchoolSetting;
use App\Models\Student;
use App\Services\Email\EventNotificationService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MessageCenterController extends Controller
{
    public function send(Request $request, EventNotificationService $notifications)
    {
        $validated = $request->validate([
            'language' => ['required', 'in:english,hindi'],
            'channel' => ['required', 'in:email,sms,whatsapp'],
            'audience' => ['required', 'in:students,parents,both'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['integer', 'distinct', 'exists:students,id'],
            'schedule_at' => ['nullable', 'date', 'after:now'],
        ]);

        if ($validated['channel'] !== 'email') {
            return response()->json([
                'message' => strtoupper($validated['channel']) . ' credentials are not configured yet.',
            ], 422);
        }

        if (!$notifications->canSendEmail()) {
            return response()->json([
                'message' => 'Email credentials are not configured. Enable SMTP first.',
            ], 422);
        }

        $students = Student::query()
            ->with(['user', 'profile', 'parents.user'])
            ->whereIn('id', $validated['student_ids'])
            ->get();

        if ($students->isEmpty()) {
            return response()->json([
                'message' => 'No students were found for the selected recipients.',
            ], 422);
        }

        if (!empty($validated['schedule_at'])) {
            $scheduled = ScheduledMessage::query()->create([
                'language' => $validated['language'],
                'channel' => $validated['channel'],
                'audience' => $validated['audience'],
                'subject' => (string) ($validated['subject'] ?? ''),
                'message' => (string) $validated['message'],
                'student_ids' => array_values($validated['student_ids']),
                'scheduled_for' => $validated['schedule_at'],
                'status' => 'scheduled',
                'created_by' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Message scheduled successfully.',
                'data' => [
                    'language' => $validated['language'],
                    'channel' => $validated['channel'],
                    'audience' => $validated['audience'],
                    'scheduled' => true,
                    'scheduled_for' => $scheduled->scheduled_for?->toISOString(),
                    'scheduled_message_id' => $scheduled->id,
                    'students_count' => $students->count(),
                    'recipient_count' => 0,
                    'batch_id' => null,
                    'queued_count' => 0,
                    'delivered_count' => 0,
                    'failed_count' => 0,
                ],
            ]);
        }

        $stats = $notifications->sendCustomStudentMessage(
            $students,
            $validated['audience'],
            (string) ($validated['subject'] ?? ''),
            (string) $validated['message']
        );

        if (($stats['recipient_count'] ?? 0) === 0 || empty($stats['batch_id'])) {
            return response()->json([
                'message' => 'No deliverable email address was found for the selected recipients.',
            ], 422);
        }

        return response()->json([
            'message' => 'Message queued for email delivery.',
            'data' => [
                'language' => $validated['language'],
                'channel' => $validated['channel'],
                'audience' => $validated['audience'],
                'scheduled' => false,
                'scheduled_for' => null,
                'scheduled_message_id' => null,
                'batch_id' => $stats['batch_id'],
                'students_count' => $stats['students_count'],
                'recipient_count' => $stats['recipient_count'],
                'queued_count' => $stats['queued_count'],
                'delivered_count' => $stats['delivered_count'],
                'failed_count' => $stats['failed_count'],
            ],
        ]);
    }

    public function status(string $batchId, EventNotificationService $notifications)
    {
        $stats = $notifications->batchStats($batchId);

        if ($stats === null) {
            throw ValidationException::withMessages([
                'batch_id' => 'Message batch not found.',
            ]);
        }

        return response()->json($stats);
    }

    public function birthdaySettings()
    {
        $settings = SchoolSetting::getValues([
            'birthday_email_enabled',
            'birthday_email_audience',
            'birthday_email_subject',
            'birthday_email_message',
            'birthday_email_send_time',
        ]);

        return response()->json([
            'enabled' => ($settings['birthday_email_enabled'] ?? '0') === '1',
            'audience' => in_array(($settings['birthday_email_audience'] ?? 'parents'), ['students', 'parents', 'both'], true)
                ? $settings['birthday_email_audience']
                : 'parents',
            'subject' => $settings['birthday_email_subject'] ?? 'Happy Birthday from School',
            'message' => $settings['birthday_email_message'] ?? 'Wishing you a very happy birthday and a wonderful year ahead.',
            'send_time' => $settings['birthday_email_send_time'] ?? '08:00',
        ]);
    }

    public function saveBirthdaySettings(Request $request)
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'audience' => ['required', 'in:students,parents,both'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
            'send_time' => ['required', 'date_format:H:i'],
        ]);

        SchoolSetting::putValue('birthday_email_enabled', $validated['enabled'] ? '1' : '0');
        SchoolSetting::putValue('birthday_email_audience', $validated['audience']);
        SchoolSetting::putValue('birthday_email_subject', trim((string) ($validated['subject'] ?? '')) ?: 'Happy Birthday from School');
        SchoolSetting::putValue(
            'birthday_email_message',
            trim((string) ($validated['message'] ?? '')) ?: 'Wishing you a very happy birthday and a wonderful year ahead.'
        );
        SchoolSetting::putValue('birthday_email_send_time', $validated['send_time']);

        return response()->json([
            'message' => 'Birthday wish settings saved successfully.',
            'data' => $this->birthdaySettings()->getData(true),
        ]);
    }
}
