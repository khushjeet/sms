param(
    [int]$Sleep = 3,
    [int]$Tries = 3,
    [int]$Timeout = 120,
    [int]$MaxJobs = 200,
    [int]$MaxTime = 3600,
    [string]$Queue = "emails"
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot

Set-Location $root

Write-Host "Starting Laravel queue worker for queue '$Queue' from $root"

while ($true) {
    php artisan queue:work --queue=$Queue --sleep=$Sleep --tries=$Tries --timeout=$Timeout --max-jobs=$MaxJobs --max-time=$MaxTime

    $exitCode = $LASTEXITCODE
    Write-Warning "Email queue worker exited with code $exitCode. Restarting in 5 seconds..."
    Start-Sleep -Seconds 5
}
