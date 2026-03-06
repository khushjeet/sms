<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Process\Process;
use Carbon\Carbon;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ops:backup-db {--path=storage/app/backups/database} {--retain-days=30}', function () {
    $connection = config('database.default');
    if ($connection !== 'mysql') {
        $this->error("ops:backup-db requires default DB connection to be mysql. Current: {$connection}");
        return self::FAILURE;
    }

    $db = config('database.connections.mysql');
    $backupDir = base_path((string) $this->option('path'));
    $retainDays = max(1, (int) $this->option('retain-days'));
    File::ensureDirectoryExists($backupDir);

    $timestamp = now()->format('Ymd_His');
    $databaseName = (string) ($db['database'] ?? 'database');
    $baseName = "{$databaseName}_{$timestamp}";
    $sqlPath = $backupDir . DIRECTORY_SEPARATOR . "{$baseName}.sql";
    $hashPath = $backupDir . DIRECTORY_SEPARATOR . "{$baseName}.sha256";
    $dumpHandle = @fopen($sqlPath, 'wb');
    if ($dumpHandle === false) {
        $this->error("Unable to open backup file for writing: {$sqlPath}");
        return self::FAILURE;
    }

    $command = [
        'mysqldump',
        '--single-transaction',
        '--quick',
        '--routines',
        '--triggers',
        '-h',
        (string) ($db['host'] ?? '127.0.0.1'),
        '-P',
        (string) ($db['port'] ?? '3306'),
        '-u',
        (string) ($db['username'] ?? 'root'),
        $databaseName,
    ];

    $process = new Process(
        $command,
        null,
        ['MYSQL_PWD' => (string) ($db['password'] ?? '')],
    );
    $process->setTimeout(1800);
    $writeFailed = false;
    $process->run(function (string $type, string $buffer) use ($dumpHandle, &$writeFailed): void {
        if ($type !== Process::OUT || $buffer === '') {
            return;
        }

        if (@fwrite($dumpHandle, $buffer) === false) {
            $writeFailed = true;
        }
    });
    fclose($dumpHandle);

    if (!$process->isSuccessful() || $writeFailed) {
        @unlink($sqlPath);
        if ($writeFailed) {
            $this->error('Database backup failed while writing dump output to disk.');
            return self::FAILURE;
        }
        $this->error('Database backup failed: ' . trim($process->getErrorOutput()));
        return self::FAILURE;
    }

    $checksum = hash_file('sha256', $sqlPath);
    if ($checksum === false) {
        @unlink($sqlPath);
        $this->error('Database backup failed: unable to compute checksum.');
        return self::FAILURE;
    }

    File::put($hashPath, "{$checksum}  " . basename($sqlPath) . PHP_EOL);

    $cutoff = now()->subDays($retainDays)->getTimestamp();
    $backupNamePattern = '/^' . preg_quote($databaseName, '/') . '_\d{8}_\d{6}\.(sql|sha256)$/';
    foreach (File::files($backupDir) as $file) {
        if (!preg_match($backupNamePattern, $file->getFilename())) {
            continue;
        }

        if ($file->getMTime() < $cutoff) {
            @unlink($file->getPathname());
        }
    }

    $this->info("Backup written: {$sqlPath}");
    $this->line("Checksum: {$checksum}");
    return self::SUCCESS;
})->purpose('Create a MySQL backup with retention cleanup');

Artisan::command('ops:restore-drill {--path=storage/app/backups/database}', function () {
    $connection = config('database.default');
    if ($connection !== 'mysql') {
        $this->error("ops:restore-drill requires default DB connection to be mysql. Current: {$connection}");
        return self::FAILURE;
    }

    $db = config('database.connections.mysql');
    $backupDir = base_path((string) $this->option('path'));
    if (!File::exists($backupDir)) {
        $this->error("Backup directory not found: {$backupDir}");
        return self::FAILURE;
    }

    $latestSql = collect(File::files($backupDir))
        ->filter(fn ($file) => str_ends_with($file->getFilename(), '.sql'))
        ->sortByDesc(fn ($file) => $file->getMTime())
        ->first();

    if (!$latestSql) {
        $this->error('No .sql backup file found for restore drill.');
        return self::FAILURE;
    }

    $latestSqlPath = $latestSql->getPathname();
    $latestHashPath = preg_replace('/\.sql$/', '.sha256', $latestSqlPath);
    if (!is_string($latestHashPath) || !File::exists($latestHashPath)) {
        $this->error('Restore drill failed: checksum file missing for latest backup.');
        return self::FAILURE;
    }

    $checksumLine = trim((string) File::get($latestHashPath));
    $expectedChecksum = strtok($checksumLine, " \t");
    if (!is_string($expectedChecksum) || !preg_match('/^[a-f0-9]{64}$/i', $expectedChecksum)) {
        $this->error('Restore drill failed: checksum file is malformed.');
        return self::FAILURE;
    }

    $actualChecksum = hash_file('sha256', $latestSqlPath);
    if ($actualChecksum === false || !hash_equals(strtolower($expectedChecksum), strtolower($actualChecksum))) {
        $this->error('Restore drill failed: checksum mismatch on latest backup.');
        return self::FAILURE;
    }

    $restoreDb = sprintf(
        '%s_restore_drill_%s',
        preg_replace('/[^A-Za-z0-9_]/', '_', (string) ($db['database'] ?? 'database')),
        now()->format('YmdHis')
    );

    $host = (string) ($db['host'] ?? '127.0.0.1');
    $port = (string) ($db['port'] ?? '3306');
    $user = (string) ($db['username'] ?? 'root');
    $password = (string) ($db['password'] ?? '');

    $runMysql = function (array $args, $input = null) use ($host, $port, $user, $password): Process {
        $process = new Process(
            array_merge(['mysql', '-h', $host, '-P', $port, '-u', $user], $args),
            null,
            ['MYSQL_PWD' => $password]
        );
        if ($input !== null) {
            $process->setInput($input);
        }
        $process->setTimeout(1800);
        $process->run();
        return $process;
    };

    try {
        $create = $runMysql(['-e', "CREATE DATABASE `{$restoreDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"]);
        if (!$create->isSuccessful()) {
            $this->error('Restore drill create-db failed: ' . trim($create->getErrorOutput()));
            return self::FAILURE;
        }

        $sourceHandle = @fopen($latestSqlPath, 'rb');
        if ($sourceHandle === false) {
            $this->error("Restore drill failed: unable to open backup file {$latestSql->getFilename()}");
            return self::FAILURE;
        }

        $restore = $runMysql(['-D', $restoreDb], $sourceHandle);
        fclose($sourceHandle);
        if (!$restore->isSuccessful()) {
            $this->error('Restore drill import failed: ' . trim($restore->getErrorOutput()));
            return self::FAILURE;
        }

        $verify = $runMysql([
            '-D',
            $restoreDb,
            '-Nse',
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = "migrations";',
        ]);
        if (!$verify->isSuccessful()) {
            $this->error('Restore drill verification query failed: ' . trim($verify->getErrorOutput()));
            return self::FAILURE;
        }

        $migrationsTableCount = (int) trim($verify->getOutput());
        if ($migrationsTableCount < 1) {
            $this->error('Restore drill failed: migrations table missing in restored database.');
            return self::FAILURE;
        }

        $this->info('Restore drill passed.');
        $this->line('Backup verified: ' . $latestSql->getFilename());
        return self::SUCCESS;
    } finally {
        $drop = $runMysql(['-e', "DROP DATABASE IF EXISTS `{$restoreDb}`;"]);
        if (!$drop->isSuccessful()) {
            $this->warn('Warning: could not drop restore drill database ' . $restoreDb);
        }
    }
})->purpose('Restore latest backup to a temp database and validate it');

Schedule::command('ops:backup-db')->dailyAt('02:00');
Schedule::command('ops:restore-drill')->weeklyOn(0, '03:00');

Artisan::command('rbac:sync-legacy-roles', function () {
    if (!\Illuminate\Support\Facades\Schema::hasTable('users') || !\Illuminate\Support\Facades\Schema::hasTable('roles') || !\Illuminate\Support\Facades\Schema::hasTable('user_roles')) {
        $this->error('RBAC tables are not available. Run migrations first.');
        return self::FAILURE;
    }

    $roles = \App\Models\Role::query()->pluck('id', 'name');
    $synced = 0;

    \App\Models\User::query()->whereNotNull('role')->chunkById(500, function ($users) use (&$synced, $roles) {
        foreach ($users as $user) {
            $roleName = (string) $user->role;
            if (!$roles->has($roleName)) {
                $role = \App\Models\Role::query()->firstOrCreate(
                    ['name' => $roleName],
                    ['description' => ucfirst(str_replace('_', ' ', $roleName)), 'is_system_role' => true]
                );
                $roles->put($roleName, $role->id);
            }

            $already = \Illuminate\Support\Facades\DB::table('user_roles')
                ->where('user_id', $user->id)
                ->where('role_id', $roles[$roleName])
                ->exists();

            if (!$already) {
                \Illuminate\Support\Facades\DB::table('user_roles')->insert([
                    'user_id' => $user->id,
                    'role_id' => $roles[$roleName],
                    'assigned_at' => now(),
                    'assigned_by' => null,
                    'expires_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $synced++;
            }
        }
    });

    $this->info("Legacy role sync complete. New assignments created: {$synced}");
    return self::SUCCESS;
})->purpose('Backfill user_roles from legacy users.role data safely');

Artisan::command('attendance:auto-punchout', function () {
    if (!\Illuminate\Support\Facades\Schema::hasTable('staff_attendance_sessions')) {
        $this->warn('staff_attendance_sessions table does not exist. Skipping.');
        return self::SUCCESS;
    }

    $openSessions = \App\Models\StaffAttendanceSession::query()
        ->whereNull('punch_out_at')
        ->with('policy')
        ->orderBy('id')
        ->get();

    $autoPunched = 0;

    foreach ($openSessions as $session) {
        $timezone = $session->timezone ?: 'UTC';
        $cutoffTime = $session->policy?->auto_punch_out_time ?? '00:00:00';
        $cutoffAt = Carbon::parse(
            $session->attendance_date->toDateString() . ' ' . $cutoffTime,
            $timezone
        );

        if ($session->punch_in_at && $cutoffAt->lessThanOrEqualTo($session->punch_in_at->copy()->timezone($timezone))) {
            $cutoffAt->addDay();
        }

        $nowInZone = now()->copy()->timezone($timezone);
        if ($nowInZone->lt($cutoffAt)) {
            continue;
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($session, $cutoffAt, &$autoPunched): void {
            $punchInAt = $session->punch_in_at ?: $cutoffAt->copy()->utc();
            $punchOutAt = $cutoffAt->copy()->utc();
            $minutes = max(0, (int) $punchInAt->diffInMinutes($punchOutAt, false));

            $session->update([
                'punch_out_at' => $punchOutAt,
                'punch_out_source' => 'system',
                'is_auto_punch_out' => true,
                'auto_punch_out_at' => $punchOutAt,
                'auto_punch_out_reason' => 'No manual punch-out before policy cutoff.',
                'duration_minutes' => $minutes,
                'review_status' => 'pending',
            ]);

            $session->punchEvents()->create([
                'staff_id' => $session->staff_id,
                'punch_type' => 'auto_out',
                'punched_at' => $punchOutAt,
                'source' => 'system',
                'is_system_generated' => true,
                'captured_by_user_id' => null,
                'note' => 'Auto punch-out applied by policy cutoff.',
            ]);

            $autoPunched++;
        });
    }

    $this->info("Auto punch-out completed. Sessions updated: {$autoPunched}");
    return self::SUCCESS;
})->purpose('Auto punch-out open staff attendance sessions at policy cutoff time');

Schedule::command('attendance:auto-punchout')->hourlyAt(5);

Artisan::command('attendance:auto-approve-pending', function () {
    if (!\Illuminate\Support\Facades\Schema::hasTable('staff_attendance_sessions')) {
        $this->warn('staff_attendance_sessions table does not exist. Skipping.');
        return self::SUCCESS;
    }

    $yesterday = now()->subDay()->toDateString();
    $updated = 0;

    \App\Models\StaffAttendanceSession::query()
        ->where('review_status', 'pending')
        ->whereDate('attendance_date', '<=', $yesterday)
        ->orderBy('id')
        ->chunkById(200, function ($sessions) use (&$updated): void {
            foreach ($sessions as $session) {
                \Illuminate\Support\Facades\DB::transaction(function () use ($session, &$updated): void {
                    $fromStatus = $session->review_status;

                    $session->update([
                        'review_status' => 'approved',
                        'reviewed_by' => null,
                        'reviewed_at' => now(),
                        'review_note' => 'Auto-approved by midnight policy.',
                    ]);

                    \App\Models\StaffAttendanceApprovalLog::query()->create([
                        'staff_attendance_session_id' => $session->id,
                        'from_status' => $fromStatus,
                        'to_status' => 'approved',
                        'action' => 'approved',
                        'acted_by' => null,
                        'acted_at' => now(),
                        'remarks' => 'Auto-approved by midnight policy.',
                    ]);

                    \App\Models\StaffAttendanceRecord::query()
                        ->where('staff_attendance_session_id', $session->id)
                        ->update([
                            'approval_status' => 'approved',
                            'approved_by' => null,
                            'approved_at' => now(),
                            'updated_by' => null,
                        ]);

                    $updated++;
                });
            }
        });

    $this->info("Auto-approve completed. Sessions updated: {$updated}");
    return self::SUCCESS;
})->purpose('Auto-approve pending staff self-attendance sessions after midnight');

Schedule::command('attendance:auto-approve-pending')->dailyAt('00:10');
