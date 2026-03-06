# School Management System (SMS) - Current Project README

Laravel 12 API backend for a school management system with active modules for authentication, student lifecycle, attendance, finance, transport billing, and expense durability controls.

## Current Status (as of February 22, 2026)

Implemented and active:
- Authentication via Laravel Sanctum (`/api/v1/login`, logout, token revocation)
- Role-based and module-based middleware authorization
- Student lifecycle management (CRUD, avatar, academic history, financial summary)
- Enrollment workflows (create, update, promote, repeat, transfer)
- Attendance workflows
- Marking, section/student views, lock flow, section statistics
- Report search and live search
- Monthly/session reports + bulk monthly exports
- Academic structure and session controls
- Academic years (`current`, set current, close), classes, and sections
- Finance core
- Fee structures, fee heads, installments, optional services, hostel fees
- Fee assignment + discounts
- Student/enrollment/class installment assignment
- Payments, refunds, receipts (JSON + printable HTML), unified receipt by enrollment
- Ledger views (student, class, enrollment), balances, reversals, special fee posting
- Financial holds management
- Finance reports (fees due, collection, transport route-wise)
- Expense management
- Expense entry, reversal, receipt upload and retrieval
- Expense audit report and downloadable entries
- Transport fee integration
- Routes, stops, assignment (single + bulk), stop operation
- Transport charge lookup by enrollment
- Transport fee-cycle generation
- Durability operations and scheduling
- `ops:backup-db` with checksum + retention cleanup
- `ops:restore-drill` with temp DB restore validation
- Scheduled backup/restore in `routes/console.php`

Partially implemented / pending:
- Subject, exam, result, staff, timetable, library, and notification APIs remain commented/scaffolded and are not fully active.

## Tech Stack

- PHP `^8.2`
- Laravel `^12.0`
- Laravel Sanctum `^4.3`
- MySQL (primary runtime database)
- Pest + Laravel testing plugin
- Vite tooling for Laravel assets
- Angular frontend in `frontend/`

## Repository Layout

- `app/Http/Controllers/Api` - API controllers (core, finance, transport, expenses)
- `app/Models` - domain models
- `routes/api.php` - API route map (`/api/v1/*`)
- `routes/web.php` - web routes (includes receipt verification page)
- `routes/console.php` - custom ops commands + schedules
- `database/migrations` - schema evolution
- `tests/Feature` - finance, transport, and expense durability/regression tests
- `frontend/` - Angular client

## Backend Setup

1. Install dependencies:
```bash
composer install
npm install
```

2. Configure environment:
```bash
cp .env.example .env
php artisan key:generate
```

3. Set DB credentials in `.env` and migrate:
```bash
php artisan migrate
```

4. Start backend:
```bash
php artisan serve
```

API base URL:
- `http://127.0.0.1:8000/api/v1`

## Frontend Setup (Angular)

From `frontend/`:
```bash
npm install
npm run start
```

Recent frontend updates:
- Attendance monthly reporting is session-driven
- Live attendance search supports student/admission/enrollment identifiers
- Bulk monthly attendance exports available
- Attendance/classes/sections views improved for mobile devices

## Local Dev Commands

- Laravel + queue + Vite:
```bash
composer run dev
```

- Run tests:
```bash
php artisan test
```

- Backup + restore drill:
```bash
php artisan ops:backup-db
php artisan ops:restore-drill
```

- Run scheduler worker:
```bash
php artisan schedule:work
```

- Run durability governance audit:
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\durability-audit.ps1 -Strict
```

## Test Database Resolution

`tests/bootstrap.php` resolves test DB in this order:
1. `TEST_DB_*` variables
2. In-memory sqlite (`:memory:`) if `pdo_sqlite` exists
3. MySQL fallback with default schema `sms_test`

Example explicit override:
```bash
TEST_DB_CONNECTION=mysql TEST_DB_DATABASE=sms_test php artisan test
```

## Authentication Quick Start

1. Create/use a user with a valid `role`.
2. Login:
```http
POST /api/v1/login
Content-Type: application/json

{
  "email": "admin@school.com",
  "password": "password"
}
```
3. Send bearer token:
```http
Authorization: Bearer <token>
```

## Key API Areas

- Auth: `POST /api/v1/login`, `POST /api/v1/logout`, `GET /api/v1/user`
- Students: `GET|POST|PUT|DELETE /api/v1/students/*`
- Enrollments: `GET|POST|PUT /api/v1/enrollments/*` + promote/repeat/transfer
- Attendance: mark, reports, statistics, lock under `/api/v1/attendance/*`
- Academic years/classes/sections under `/api/v1/academic-years/*`, `/api/v1/classes/*`, `/api/v1/sections/*`
- Transport under `/api/v1/transport/*`
- Finance core under `/api/v1/finance/*`
- Expenses under `/api/v1/finance/expenses*` and `/api/v1/finance/reports/expenses/*`

For exact route signatures and role rules, refer to `routes/api.php`.

## Regression Test Coverage

- `tests/Feature/FinanceDurabilityTest.php`
- `tests/Feature/ExpenseDurabilityTest.php`
- `tests/Feature/TransportAssignmentLedgerTest.php`
- `tests/Feature/InstallmentNarrationRegressionTest.php`

These tests cover balanced ledger posting, idempotency/reversal safeguards, locked period behavior, transport-assignment ledger effects, installment narration regression, and expense durability guards.

## Known Notes

- Receipt verification page:
- `GET /verify/receipts/{receiptNumber}`
- Finance receipt/PDF flows include recent reliability fixes (logo fallback/data fallback).

Reference docs:
- `QUICKSTART.md`
- `PROJECT_SUMMARY.md`
- `DATABASE_SCHEMA.md`
- `DURABILITY_STANDARD.md`

## Version

- Last updated: February 22, 2026
- Baseline: Laravel 12 + Sanctum API backend with finance, expense durability, and transport-ledger integration
