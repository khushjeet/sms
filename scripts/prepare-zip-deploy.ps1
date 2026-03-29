[CmdletBinding()]
param(
    [string]$OutputDirectory = ".dist\\deploy-package",
    [string]$ZipPath = ".dist\\sms-deploy.zip",
    [switch]$SkipFrontendBuild,
    [switch]$SkipEnv
)

$ErrorActionPreference = "Stop"

function Invoke-Step {
    param(
        [string]$Message,
        [scriptblock]$Action
    )

    Write-Host "==> $Message"
    & $Action
}

$projectRoot = Split-Path -Parent $PSScriptRoot
$stagingRoot = Join-Path $projectRoot $OutputDirectory
$zipFile = Join-Path $projectRoot $ZipPath
$frontendDist = Join-Path $projectRoot "frontend\\dist\\frontend\\browser"
$timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$workingRoot = Join-Path $projectRoot ".dist\\staging-$timestamp"
$runtimeRoot = Join-Path $workingRoot "laravel"

if (-not (Test-Path (Join-Path $projectRoot "vendor\\autoload.php"))) {
    throw "vendor directory is missing. Run 'composer install --no-dev --optimize-autoloader' before packaging."
}

if (-not $SkipFrontendBuild) {
    Invoke-Step "Building Angular frontend for production" {
        Push-Location (Join-Path $projectRoot "frontend")
        try {
            & npm run build:production
            if ($LASTEXITCODE -ne 0) {
                throw "Frontend production build failed."
            }
        }
        finally {
            Pop-Location
        }
    }
}

if (-not (Test-Path $frontendDist)) {
    throw "Frontend build output not found at '$frontendDist'."
}

Invoke-Step "Resetting staging directory" {
    if (-not (Test-Path (Split-Path -Parent $workingRoot))) {
        New-Item -ItemType Directory -Path (Split-Path -Parent $workingRoot) | Out-Null
    }

    New-Item -ItemType Directory -Path $workingRoot | Out-Null
    New-Item -ItemType Directory -Path $runtimeRoot | Out-Null
}

$directoriesToCopy = @(
    "app",
    "bootstrap",
    "config",
    "database",
    "public",
    "resources",
    "routes",
    "storage",
    "vendor"
)

foreach ($directory in $directoriesToCopy) {
    Invoke-Step "Copying $directory" {
        Copy-Item -Path (Join-Path $projectRoot $directory) -Destination $runtimeRoot -Recurse -Force
    }
}

$filesToCopy = @(
    "artisan",
    "composer.json",
    "composer.lock",
    ".env.example",
    "README.md"
)

if ((-not $SkipEnv) -and (Test-Path (Join-Path $projectRoot ".env"))) {
    $filesToCopy += ".env"
}

foreach ($file in $filesToCopy) {
    Invoke-Step "Copying $file" {
        Copy-Item -Path (Join-Path $projectRoot $file) -Destination $runtimeRoot -Force
    }
}

Invoke-Step "Publishing public entrypoint at package root" {
    $sourcePublic = Join-Path $projectRoot "public"

    Get-ChildItem -Path $sourcePublic -Force | Where-Object {
        $_.Name -ne "storage"
    } | ForEach-Object {
        Copy-Item -Path $_.FullName -Destination $workingRoot -Recurse -Force
    }

    Copy-Item -Path (Join-Path $frontendDist "*") -Destination $workingRoot -Recurse -Force
}

Invoke-Step "Copying public storage assets into package root" {
    $storageSource = Join-Path $runtimeRoot "storage\\app\\public"
    $storageTarget = Join-Path $workingRoot "storage"

    if (-not (Test-Path $storageTarget)) {
        New-Item -ItemType Directory -Path $storageTarget | Out-Null
    }

    if (Test-Path $storageSource) {
        Get-ChildItem -Path $storageSource -Force | ForEach-Object {
            Copy-Item -Path $_.FullName -Destination $storageTarget -Recurse -Force
        }
    }
}

Invoke-Step "Writing shared-hosting root index.php" {
    $indexPhp = @'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = __DIR__.'/laravel/storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/laravel/vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/laravel/bootstrap/app.php';
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
'@

    Set-Content -Path (Join-Path $workingRoot "index.php") -Value $indexPhp
}

Invoke-Step "Writing shared-hosting root .htaccess" {
    $htaccess = @'
<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews -Indexes
    </IfModule>

    RewriteEngine On

    RewriteRule ^laravel(?:/|$) - [F,L,NC]

    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    RewriteCond %{HTTP:x-xsrf-token} .
    RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]

    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} (.+)/$
    RewriteRule ^ %1 [L,R=301]

    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>

<FilesMatch "^\.">
    Require all denied
</FilesMatch>
'@

    Set-Content -Path (Join-Path $workingRoot ".htaccess") -Value $htaccess
}

Invoke-Step "Removing local-only runtime leftovers" {
    $psyshPath = Join-Path $runtimeRoot "storage\\psysh"
    if (Test-Path $psyshPath) {
        Remove-Item -Recurse -Force $psyshPath
    }

    $logFiles = Join-Path $runtimeRoot "storage\\logs\\*.log"
    Get-ChildItem -Path $logFiles -ErrorAction SilentlyContinue | Remove-Item -Force
}

Invoke-Step "Writing deployment notes" {
    $notes = @"
ZIP upload checklist

1. Extract this ZIP directly into the domain folder for https://dashboard.ipsyogapatti.com.
2. The website root is this extracted folder itself. Do not repoint the host to public/.
3. Laravel runtime files are inside laravel/. Public frontend files are at the package root.
4. If this ZIP includes laravel/.env, keep the ZIP private because it contains live secrets.
5. If shell access is available, run:
   php laravel/artisan migrate --force
   php laravel/artisan optimize:clear
   php laravel/artisan optimize
6. Ensure laravel/storage/ and laravel/bootstrap/cache/ are writable by the web server user.

This package already includes vendor/, the built Angular frontend, and the shared-hosting entry files.
"@

    Set-Content -Path (Join-Path $workingRoot "DEPLOY_UPLOAD.txt") -Value $notes
}

Invoke-Step "Creating zip archive" {
    $zipDirectory = Split-Path -Parent $zipFile
    if (-not (Test-Path $zipDirectory)) {
        New-Item -ItemType Directory -Path $zipDirectory | Out-Null
    }

    if (Test-Path $zipFile) {
        Remove-Item -Force $zipFile
    }

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [System.IO.Compression.ZipFile]::CreateFromDirectory(
        $workingRoot,
        $zipFile,
        [System.IO.Compression.CompressionLevel]::Optimal,
        $false
    )
}

Invoke-Step "Refreshing extracted package folder" {
    if (Test-Path $stagingRoot) {
        Remove-Item -Recurse -Force $stagingRoot -ErrorAction SilentlyContinue
    }

    Move-Item -Path $workingRoot -Destination $stagingRoot
}

Write-Host ""
Write-Host "Deployment package created:"
Write-Host "Staging: $stagingRoot"
Write-Host "Zip:     $zipFile"
