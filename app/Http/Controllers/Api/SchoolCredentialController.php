<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\GenericEventMail;
use App\Models\SchoolSetting;
use App\Support\SchoolSmtpConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SchoolCredentialController extends Controller
{
    private const PASSWORD_PREFIX = 'encrypted:';

    public function show()
    {
        $settings = SchoolSetting::getValues($this->keys());

        return response()->json($this->payload($settings));
    }

    public function status()
    {
        $settings = SchoolSetting::getValues($this->keys());
        $pendingJobs = (int) DB::table('jobs')->where('queue', 'emails')->count();
        $failedJobs = (int) DB::table('failed_jobs')->where('queue', 'emails')->count();
        $oldestCreatedAt = DB::table('jobs')->where('queue', 'emails')->min('created_at');
        $oldestPendingSeconds = $oldestCreatedAt ? max(0, now()->timestamp - (int) $oldestCreatedAt) : null;
        $smtpReady = ($settings['smtp_enabled'] ?? '0') === '1'
            && trim((string) ($settings['smtp_host'] ?? '')) !== ''
            && trim((string) ($settings['smtp_port'] ?? '')) !== ''
            && trim((string) ($settings['smtp_from_address'] ?? '')) !== '';
        $workerRequired = config('queue.default') !== 'sync';
        $queueBackedUp = $pendingJobs > 0 && ($oldestPendingSeconds ?? 0) >= 120;

        $level = 'healthy';
        $message = 'SMTP is configured and no email backlog is waiting.';

        if (($settings['smtp_enabled'] ?? '0') !== '1') {
            $level = 'critical';
            $message = 'SMTP is disabled. Email alerts will not send until email notifications are enabled.';
        } elseif (!$smtpReady) {
            $level = 'critical';
            $message = 'SMTP is enabled but required host, port, or from address settings are missing.';
        } elseif ($failedJobs > 0) {
            $level = 'critical';
            $message = 'Some email jobs have already failed. Review the queue worker and SMTP credentials.';
        } elseif ($queueBackedUp) {
            $level = 'warning';
            $message = 'Email jobs are waiting in the queue. The worker may be stopped or delayed.';
        } elseif ($pendingJobs > 0) {
            $level = 'warning';
            $message = 'Email jobs are queued and waiting for the worker to process them.';
        }

        return response()->json([
            'smtp_enabled' => ($settings['smtp_enabled'] ?? '0') === '1',
            'smtp_ready' => $smtpReady,
            'queue_connection' => (string) config('queue.default', 'sync'),
            'worker_required' => $workerRequired,
            'queue_pending_count' => $pendingJobs,
            'queue_failed_count' => $failedJobs,
            'queue_oldest_pending_seconds' => $oldestPendingSeconds,
            'queue_is_backed_up' => $queueBackedUp,
            'status' => $level,
            'message' => $message,
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'smtp_enabled' => ['sometimes', 'boolean'],
            'smtp_host' => ['nullable', 'string', 'max:255'],
            'smtp_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:1000'],
            'smtp_encryption' => ['nullable', 'in:none,tls,ssl'],
            'smtp_from_address' => ['nullable', 'email', 'max:255'],
            'smtp_from_name' => ['nullable', 'string', 'max:255'],
            'smtp_reply_to_address' => ['nullable', 'email', 'max:255'],
            'smtp_reply_to_name' => ['nullable', 'string', 'max:255'],
        ]);

        $current = SchoolSetting::getValues($this->keys());

        foreach ($this->keys() as $key) {
            if (!array_key_exists($key, $validated)) {
                continue;
            }

            $value = $validated[$key];

            if ($key === 'smtp_password') {
                $normalized = trim((string) $value);

                if ($normalized === '') {
                    SchoolSetting::putValue($key, null);
                    continue;
                }

                if ($this->decodePassword($current[$key] ?? null) !== $normalized) {
                    SchoolSetting::putValue($key, self::PASSWORD_PREFIX . Crypt::encryptString($normalized));
                }

                continue;
            }

            if ($key === 'smtp_enabled') {
                SchoolSetting::putValue($key, filter_var($value, FILTER_VALIDATE_BOOL) ? '1' : '0');
                continue;
            }

            if ($key === 'smtp_port') {
                SchoolSetting::putValue($key, $value !== null ? (string) ((int) $value) : null);
                continue;
            }

            $normalized = trim((string) $value);
            SchoolSetting::putValue($key, $normalized !== '' ? $normalized : null);
        }

        return response()->json([
            'message' => 'Email credentials updated successfully.',
            'data' => $this->payload(SchoolSetting::getValues($this->keys())),
        ]);
    }

    public function test(Request $request)
    {
        $validated = $request->validate([
            'test_email' => ['required', 'email', 'max:255'],
        ]);

        $settings = SchoolSetting::getValues($this->keys());

        if (($settings['smtp_enabled'] ?? '0') !== '1') {
            return response()->json([
                'message' => 'SMTP is disabled. Enable email notifications first.',
                'data' => [
                    'connectivity' => false,
                    'delivery' => false,
                ],
            ], 422);
        }

        $host = trim((string) ($settings['smtp_host'] ?? ''));
        $port = (int) ($settings['smtp_port'] ?? 0);
        $fromAddress = trim((string) ($settings['smtp_from_address'] ?? ''));

        if ($host === '' || $port <= 0 || $fromAddress === '') {
            return response()->json([
                'message' => 'SMTP host, port, and from address are required before testing email.',
                'data' => [
                    'connectivity' => false,
                    'delivery' => false,
                ],
            ], 422);
        }

        $connectivity = $this->checkConnectivity($settings);
        if (!$connectivity['success']) {
            return response()->json([
                'message' => $connectivity['message'],
                'data' => [
                    'connectivity' => false,
                    'delivery' => false,
                    'host' => $host,
                    'port' => $port,
                ],
            ], 422);
        }

        $mailer = $this->configureRuntimeMailer($settings);
        if ($mailer === null) {
            return response()->json([
                'message' => 'Unable to configure the runtime SMTP mailer.',
                'data' => [
                    'connectivity' => true,
                    'delivery' => false,
                ],
            ], 422);
        }

        try {
            $mail = new GenericEventMail(
                'SMTP test email',
                'SMTP connection verified',
                [
                    'This is a live test email from the school credentials screen.',
                    'If you received this email, SMTP connectivity and authentication are working.',
                    'Time: ' . now()->toDateTimeString(),
                ],
                SchoolSetting::getValue('school_name', config('app.name'))
            );

            $replyToAddress = trim((string) ($settings['smtp_reply_to_address'] ?? ''));
            if ($replyToAddress !== '') {
                $mail->replyTo(
                    $replyToAddress,
                    trim((string) ($settings['smtp_reply_to_name'] ?? '')) ?: null
                );
            }

            Mail::mailer($mailer)->to($validated['test_email'])->send($mail);

            return response()->json([
                'message' => 'Test email sent successfully.',
                'data' => [
                    'connectivity' => true,
                    'delivery' => true,
                    'host' => $host,
                    'port' => $port,
                    'recipient' => $validated['test_email'],
                ],
            ]);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'SMTP server is reachable, but sending failed: ' . $exception->getMessage(),
                'data' => [
                    'connectivity' => true,
                    'delivery' => false,
                    'host' => $host,
                    'port' => $port,
                    'recipient' => $validated['test_email'],
                ],
            ], 422);
        }
    }

    private function payload(array $settings): array
    {
        return [
            'smtp_enabled' => ($settings['smtp_enabled'] ?? '0') === '1',
            'smtp_host' => $settings['smtp_host'] ?? null,
            'smtp_port' => isset($settings['smtp_port']) ? (int) $settings['smtp_port'] : null,
            'smtp_username' => $settings['smtp_username'] ?? null,
            'smtp_password' => $this->decodePassword($settings['smtp_password'] ?? null),
            'smtp_encryption' => $this->normalizeEncryption($settings['smtp_encryption'] ?? null),
            'smtp_from_address' => $settings['smtp_from_address'] ?? null,
            'smtp_from_name' => $settings['smtp_from_name'] ?? null,
            'smtp_reply_to_address' => $settings['smtp_reply_to_address'] ?? null,
            'smtp_reply_to_name' => $settings['smtp_reply_to_name'] ?? null,
        ];
    }

    private function keys(): array
    {
        return [
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
        ];
    }

    private function configureRuntimeMailer(array $settings): ?string
    {
        $host = trim((string) ($settings['smtp_host'] ?? ''));
        $port = (int) ($settings['smtp_port'] ?? 0);
        $fromAddress = trim((string) ($settings['smtp_from_address'] ?? ''));

        if ($host === '' || $port <= 0 || $fromAddress === '') {
            return null;
        }

        $mailerConfig = SchoolSmtpConfig::buildMailerConfig(
            $settings,
            $this->decodePassword($settings['smtp_password'] ?? null),
            trim((string) ($settings['smtp_from_name'] ?? '')) ?: config('app.name'),
            parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost',
            15
        );

        if ($mailerConfig === null) {
            return null;
        }

        config([
            'mail.default' => 'school_runtime_smtp',
            'mail.mailers.school_runtime_smtp' => $mailerConfig,
            'mail.from.address' => $fromAddress,
            'mail.from.name' => trim((string) ($settings['smtp_from_name'] ?? '')) ?: config('app.name'),
        ]);

        app('mail.manager')->purge('school_runtime_smtp');

        return 'school_runtime_smtp';
    }

    private function checkConnectivity(array $settings): array
    {
        $host = trim((string) ($settings['smtp_host'] ?? ''));
        $port = (int) ($settings['smtp_port'] ?? 0);
        $encryption = strtolower(trim((string) ($settings['smtp_encryption'] ?? 'none')));
        $transportHost = $encryption === 'ssl' ? 'ssl://' . $host : $host;

        $errno = 0;
        $errstr = '';
        $connection = @fsockopen($transportHost, $port, $errno, $errstr, 10);

        if (!is_resource($connection)) {
            return [
                'success' => false,
                'message' => sprintf(
                    'Unable to connect to SMTP server %s:%d. %s',
                    $host,
                    $port,
                    trim($errstr) !== '' ? trim($errstr) : ('Socket error ' . $errno)
                ),
            ];
        }

        fclose($connection);

        return [
            'success' => true,
            'message' => 'SMTP server is reachable.',
        ];
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

    private function normalizeEncryption(?string $value): string
    {
        return SchoolSmtpConfig::normalizeEncryption($value);
    }
}
