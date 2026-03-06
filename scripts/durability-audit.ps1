param(
    [switch]$Strict
)

$ErrorActionPreference = "Stop"

function Write-CheckResult {
    param(
        [string]$Name,
        [bool]$Passed,
        [string]$Detail
    )

    if ($Passed) {
        Write-Host "[PASS] $Name - $Detail" -ForegroundColor Green
    } else {
        Write-Host "[FAIL] $Name - $Detail" -ForegroundColor Red
    }
}

$root = Resolve-Path (Join-Path $PSScriptRoot "..")
$failures = 0

function Assert-Check {
    param(
        [string]$Name,
        [scriptblock]$Check,
        [string]$PassDetail,
        [string]$FailDetail
    )

    try {
        $ok = & $Check
        if ($ok) {
            Write-CheckResult -Name $Name -Passed $true -Detail $PassDetail
            return
        }
    } catch {
        # Fall through to failure path.
    }

    Write-CheckResult -Name $Name -Passed $false -Detail $FailDetail
    $script:failures++
}

Assert-Check `
    -Name "Durability standard doc present" `
    -Check { Test-Path (Join-Path $root "DURABILITY_STANDARD.md") } `
    -PassDetail "DURABILITY_STANDARD.md found." `
    -FailDetail "DURABILITY_STANDARD.md missing."

Assert-Check `
    -Name "CI workflow present" `
    -Check { Test-Path (Join-Path $root ".github/workflows/ci.yml") } `
    -PassDetail "CI workflow found." `
    -FailDetail "CI workflow missing."

Assert-Check `
    -Name "Dependabot configured" `
    -Check { Test-Path (Join-Path $root ".github/dependabot.yml") } `
    -PassDetail "Dependabot config found." `
    -FailDetail "Dependabot config missing."

$consoleFile = Join-Path $root "routes/console.php"
Assert-Check `
    -Name "Backup command registered" `
    -Check {
        (Test-Path $consoleFile) -and ((Get-Content $consoleFile -Raw) -match "ops:backup-db")
    } `
    -PassDetail "ops:backup-db command wiring found." `
    -FailDetail "ops:backup-db command wiring not found."

Assert-Check `
    -Name "Restore drill command registered" `
    -Check {
        (Test-Path $consoleFile) -and ((Get-Content $consoleFile -Raw) -match "ops:restore-drill")
    } `
    -PassDetail "ops:restore-drill command wiring found." `
    -FailDetail "ops:restore-drill command wiring not found."

Assert-Check `
    -Name "Backup schedule wiring present" `
    -Check {
        (Test-Path $consoleFile) -and ((Get-Content $consoleFile -Raw) -match "Schedule::command\('ops:backup-db'\)")
    } `
    -PassDetail "Backup schedule found." `
    -FailDetail "Backup schedule missing."

Assert-Check `
    -Name "Restore drill schedule wiring present" `
    -Check {
        (Test-Path $consoleFile) -and ((Get-Content $consoleFile -Raw) -match "Schedule::command\('ops:restore-drill'\)")
    } `
    -PassDetail "Restore drill schedule found." `
    -FailDetail "Restore drill schedule missing."

$durabilityTests = @(
    "tests/Feature/FinanceDurabilityTest.php",
    "tests/Feature/ExpenseDurabilityTest.php",
    "tests/Feature/HrPayrollDurabilityTest.php",
    "tests/Feature/TransportAssignmentLedgerTest.php"
)

$missingTests = @()
foreach ($testFile in $durabilityTests) {
    $path = Join-Path $root $testFile
    if (-not (Test-Path $path)) {
        $missingTests += $testFile
    }
}

if ($missingTests.Count -eq 0) {
    Write-CheckResult -Name "Core durability test files present" -Passed $true -Detail "All required durability test files exist."
} else {
    Write-CheckResult -Name "Core durability test files present" -Passed $false -Detail ("Missing: " + ($missingTests -join ", "))
    $failures++
}

if ($Strict -and $failures -gt 0) {
    Write-Error "Durability audit failed with $failures failure(s)."
    exit 1
}

if ($failures -gt 0) {
    Write-Host "Durability audit completed with $failures failure(s)." -ForegroundColor Yellow
    exit 0
}

Write-Host "Durability audit passed with zero failures." -ForegroundColor Green
exit 0
