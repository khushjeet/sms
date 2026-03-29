<?php

namespace App\Support;

final class SchoolSmtpConfig
{
    public static function normalizeEncryption(?string $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['tls', 'ssl'], true) ? $normalized : 'none';
    }

    public static function buildMailerConfig(
        array $settings,
        ?string $password,
        string $defaultFromName,
        string $localDomain = 'localhost',
        ?int $timeout = null
    ): ?array {
        $host = trim((string) ($settings['smtp_host'] ?? ''));
        $port = (int) ($settings['smtp_port'] ?? 0);
        $fromAddress = trim((string) ($settings['smtp_from_address'] ?? ''));

        if ($host === '' || $port <= 0 || $fromAddress === '') {
            return null;
        }

        $encryption = self::normalizeEncryption($settings['smtp_encryption'] ?? null);

        return [
            'transport' => 'smtp',
            'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'auto_tls' => $encryption === 'tls',
            'host' => $host,
            'port' => $port,
            'username' => trim((string) ($settings['smtp_username'] ?? '')) ?: null,
            'password' => $password,
            'timeout' => $timeout,
            'local_domain' => $localDomain,
        ];
    }
}
