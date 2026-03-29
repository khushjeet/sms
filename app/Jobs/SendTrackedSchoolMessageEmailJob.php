<?php

namespace App\Jobs;

use App\Mail\GenericEventMail;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendTrackedSchoolMessageEmailJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $email,
        public string $subjectLine,
        public string $headline,
        public array $lines,
        public array $mailConfig
    ) {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $encryption = strtolower(trim((string) ($this->mailConfig['encryption'] ?? '')));
        $configuredScheme = strtolower(trim((string) ($this->mailConfig['scheme'] ?? '')));
        $scheme = match (true) {
            $encryption === 'ssl', $configuredScheme === 'ssl', $configuredScheme === 'smtps' => 'smtps',
            default => 'smtp',
        };
        $autoTls = $encryption === 'tls'
            || ($encryption === '' && ($this->mailConfig['auto_tls'] ?? false));

        config([
            'mail.mailers.' . ($this->mailConfig['mailer'] ?? 'school_runtime_smtp') => [
                'transport' => 'smtp',
                'scheme' => $scheme,
                'auto_tls' => $autoTls,
                'host' => $this->mailConfig['host'] ?? null,
                'port' => (int) ($this->mailConfig['port'] ?? 0),
                'username' => $this->mailConfig['username'] ?? null,
                'password' => $this->mailConfig['password'] ?? null,
                'timeout' => null,
                'local_domain' => $this->mailConfig['local_domain'] ?? 'localhost',
            ],
            'mail.from.address' => $this->mailConfig['from_address'] ?? null,
            'mail.from.name' => $this->mailConfig['from_name'] ?? null,
        ]);

        app('mail.manager')->purge($this->mailConfig['mailer'] ?? 'school_runtime_smtp');

        $mail = new GenericEventMail(
            $this->subjectLine,
            $this->headline,
            $this->lines,
            $this->mailConfig['school_name'] ?? null
        );

        if (!empty($this->mailConfig['reply_to_address'])) {
            $mail->replyTo(
                $this->mailConfig['reply_to_address'],
                $this->mailConfig['reply_to_name'] ?? null
            );
        }

        Mail::mailer($this->mailConfig['mailer'] ?? 'school_runtime_smtp')
            ->to($this->email)
            ->send($mail);
    }
}
