<?php

namespace Tests\Unit;

use App\Support\SchoolSmtpConfig;
use PHPUnit\Framework\TestCase;

class SchoolSmtpConfigTest extends TestCase
{
    public function test_tls_builds_a_valid_smtp_configuration_without_invalid_scheme(): void
    {
        $config = SchoolSmtpConfig::buildMailerConfig([
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => '587',
            'smtp_username' => 'mailer',
            'smtp_from_address' => 'noreply@example.com',
            'smtp_encryption' => 'tls',
        ], 'secret', 'Example School', 'example.com', 15);

        $this->assertNotNull($config);
        $this->assertSame('smtp', $config['scheme']);
        $this->assertTrue($config['auto_tls']);
        $this->assertSame('smtp.example.com', $config['host']);
        $this->assertSame(587, $config['port']);
    }

    public function test_ssl_builds_a_secure_smtps_configuration(): void
    {
        $config = SchoolSmtpConfig::buildMailerConfig([
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => '465',
            'smtp_from_address' => 'noreply@example.com',
            'smtp_encryption' => 'ssl',
        ], 'secret', 'Example School');

        $this->assertNotNull($config);
        $this->assertSame('smtps', $config['scheme']);
        $this->assertFalse($config['auto_tls']);
    }
}
