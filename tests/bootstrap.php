<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Test database bootstrap
|--------------------------------------------------------------------------
|
| Priority:
| 1) Explicit TEST_DB_* env variables
| 2) In-memory sqlite when pdo_sqlite is available
| 3) MySQL fallback with dedicated test schema defaults
|
*/

$explicitConnection = getenv('TEST_DB_CONNECTION');

if ($explicitConnection !== false && $explicitConnection !== '') {
    putenv("DB_CONNECTION={$explicitConnection}");
    $_ENV['DB_CONNECTION'] = $explicitConnection;
    $_SERVER['DB_CONNECTION'] = $explicitConnection;

    foreach (['HOST', 'PORT', 'DATABASE', 'USERNAME', 'PASSWORD'] as $suffix) {
        $value = getenv("TEST_DB_{$suffix}");
        if ($value !== false) {
            $key = "DB_{$suffix}";
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    return;
}

if (extension_loaded('pdo_sqlite')) {
    putenv('DB_CONNECTION=sqlite');
    putenv('DB_DATABASE=:memory:');
    $_ENV['DB_CONNECTION'] = 'sqlite';
    $_SERVER['DB_CONNECTION'] = 'sqlite';
    $_ENV['DB_DATABASE'] = ':memory:';
    $_SERVER['DB_DATABASE'] = ':memory:';
    return;
}

$mysqlDefaults = [
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
    'DB_PORT' => getenv('DB_PORT') ?: '3306',
    'DB_DATABASE' => getenv('TEST_MYSQL_DATABASE') ?: 'sms_test',
    'DB_USERNAME' => getenv('DB_USERNAME') ?: 'root',
    'DB_PASSWORD' => getenv('DB_PASSWORD') ?: '',
];

foreach ($mysqlDefaults as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

// Ensure fallback MySQL test schema exists.
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;charset=utf8mb4',
        $mysqlDefaults['DB_HOST'],
        $mysqlDefaults['DB_PORT']
    );
    $pdo = new PDO($dsn, $mysqlDefaults['DB_USERNAME'], $mysqlDefaults['DB_PASSWORD']);
    $schema = str_replace('`', '``', $mysqlDefaults['DB_DATABASE']);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (Throwable $e) {
    // Leave failure handling to Laravel test bootstrap where a clear DB error is surfaced.
}
