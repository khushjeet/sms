# Zip Deployment

Use this when you want one upload package for shared hosting, with Laravel backend and Angular frontend running from the same domain.

## Target setup

This project is now packaged for:

```text
https://dashboard.ipsyogapatti.com
```

The deployment ZIP is built so you can extract it directly into the domain folder. The extracted folder itself is the web root.

## Create the upload package

From the project root:

```powershell
composer package:deploy
```

This script:

- builds the Angular frontend in production mode
- creates a shared-hosting package in `.dist/deploy-package`
- puts public frontend files and the Laravel entrypoint at the package root
- moves the Laravel runtime into `.dist/deploy-package/laravel`
- includes `vendor/` so the server does not need `composer install`
- includes local `.env` by default if it exists
- creates `.dist/sms-deploy.zip`

If you want a package without the local `.env`, run:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\prepare-zip-deploy.ps1 -SkipEnv
```

## What to upload

Upload `.dist/sms-deploy.zip` and extract it directly inside the hosting folder for `dashboard.ipsyogapatti.com`.

After extraction, the structure is:

```text
index.php
.htaccess
index.html
assets/...
storage/...        <- public files only
laravel/...        <- app, bootstrap, config, vendor, real storage, artisan
```

Do not move files into `public/`. Do not repoint the document root to `laravel/public`.

## Server checklist

1. Extract the ZIP directly into the live domain folder.
2. Confirm `.htaccess` is enabled on the host.
3. Confirm `laravel/.env` exists and has the production values.
4. Ensure `laravel/storage/` and `laravel/bootstrap/cache/` are writable.
5. If terminal access is available, run:

```bash
php laravel/artisan migrate --force
php laravel/artisan optimize:clear
php laravel/artisan optimize
```

6. If your host supports cron, keep Laravel schedule and queue processing active.

## Email Queue Worker

All event emails now run on the `emails` queue so the HTTP request stays fast.

### Windows server

Run the dedicated worker script:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\start-email-worker.ps1
```

Recommended: register that command in Windows Task Scheduler with:

- Trigger: `At startup`
- Action: `powershell.exe`
- Arguments: `-ExecutionPolicy Bypass -File D:\path\to\sms\scripts\start-email-worker.ps1`
- Run whether user is logged on or not
- Restart on failure enabled

### Linux server with Supervisor

Example Supervisor program:

```ini
[program:sms-email-worker]
process_name=%(program_name)s_%(process_num)02d
directory=/var/www/sms
command=/usr/bin/php artisan queue:work --queue=emails --sleep=3 --tries=3 --timeout=120 --max-jobs=200 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/sms/storage/logs/email-worker.log
stopwaitsecs=3600
```

Then reload Supervisor:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start sms-email-worker:*
```

### Validation

After deployment, verify both scheduler and queue worker are alive:

```bash
php artisan queue:work --queue=emails --once
php artisan schedule:run
```

## Login-critical production values

If login works locally but fails after deployment, verify these first:

- `APP_URL` must be `https://dashboard.ipsyogapatti.com`
- `FRONTEND_URL` must also be `https://dashboard.ipsyogapatti.com`
- `CORS_ALLOWED_ORIGINS` must include `https://dashboard.ipsyogapatti.com`
- the production frontend uses relative `/api/v1`, so the frontend and backend must stay on the same origin
- after changing `laravel/.env` on the server, run `php laravel/artisan optimize:clear`

## Notes

- The production frontend uses `/api/v1`, so the uploaded SPA works from the same domain as Laravel.
- The ZIP now includes the local `.env` by default so the upload can start faster. Keep that ZIP private.
- Use `-SkipEnv` if you need a safer package for sharing or archival.

## Backup And Restore Drill

This project already includes two operational commands in [routes/console.php](d:\laravel project\sms\routes\console.php):

- `php artisan ops:backup-db`
- `php artisan ops:restore-drill`

These should be part of the actual production deployment checklist, not just local development.

## Production prerequisites

1. Install `mysql` and `mysqldump` on the server path used by PHP.
2. Configure `.env` with the production MySQL database as the default connection.
3. Ensure the backup directory exists on persistent storage:

```bash
mkdir -p storage/app/backups/database
```

4. Ensure backups are copied off-server as part of infrastructure policy.
5. Ensure Laravel scheduler is running continuously:

```bash
php artisan schedule:work
```

or system cron:

```bash
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

## First production validation

Run these once after deployment:

```bash
php artisan ops:backup-db
php artisan ops:restore-drill
```

Expected result:

- backup command writes `.sql` and `.sha256` files
- restore drill creates a temporary database, restores latest backup, verifies the `migrations` table, then drops the temp database

Do not call the deployment complete until both commands succeed on the server.

## Operating standard

- Run `ops:backup-db` daily.
- Run `ops:restore-drill` at least weekly.
- Keep the scheduler enabled at all times.
- Monitor command failures in server logs.
- Store evidence of successful restore drills with date, environment, backup filename, and operator.

## Recovery evidence log

For each restore drill, record:

- environment name
- run date/time
- source backup filename
- checksum validation status
- temporary restore database name
- verification result
- operator name

## Recommended RPO and RTO

- Target RPO: 24 hours or better
- Target RTO: 2 hours or better

Adjust these based on school operations, fee activity volume, and exam periods.
