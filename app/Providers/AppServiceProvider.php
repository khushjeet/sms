<?php

namespace App\Providers;

use App\Models\SchoolSetting;
use App\Support\SchoolSmtpConfig;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    private const RUNTIME_MAILER = 'school_runtime_smtp';
    private const PASSWORD_PREFIX = 'encrypted:';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register DomPDF explicitly to avoid relying on locked package cache files.
        $this->app->register(\Barryvdh\DomPDF\ServiceProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureSchoolSmtpMailer();

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            $baseUrl = rtrim($this->normalizeFrontendUrl(), '/');
            $email = urlencode($notifiable->getEmailForPasswordReset());

            return "{$baseUrl}/password-reset/{$token}?email={$email}";
        });
    }

    private function configureSchoolSmtpMailer(): void
    {
        $settings = SchoolSetting::getValues([
            'smtp_enabled',
            'smtp_host',
            'smtp_port',
            'smtp_username',
            'smtp_password',
            'smtp_encryption',
            'smtp_from_address',
            'smtp_from_name',
        ]);

        if (($settings['smtp_enabled'] ?? '0') !== '1') {
            return;
        }

        $fromAddress = trim((string) ($settings['smtp_from_address'] ?? ''));
        $mailerConfig = SchoolSmtpConfig::buildMailerConfig(
            $settings,
            $this->decodePassword($settings['smtp_password'] ?? null),
            trim((string) ($settings['smtp_from_name'] ?? '')) ?: config('app.name'),
            parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'
        );

        if ($mailerConfig === null) {
            return;
        }

        config([
            'mail.default' => self::RUNTIME_MAILER,
            'mail.mailers.' . self::RUNTIME_MAILER => $mailerConfig,
            'mail.from.address' => $fromAddress,
            'mail.from.name' => trim((string) ($settings['smtp_from_name'] ?? '')) ?: config('app.name'),
        ]);
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

    private function normalizeFrontendUrl(): string
    {
        $configured = trim((string) config('app.frontend_url', config('app.url')));

        if ($configured === '') {
            return rtrim((string) config('app.url'), '/');
        }

        if (!Str::startsWith($configured, ['http://', 'https://'])) {
            $configured = 'https://' . ltrim($configured, '/');
        }

        return $configured;
    }
}
