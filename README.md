# School Management System (SMS) - Current Project README

Laravel 12 API backend for a school management system with active modules for authentication, student lifecycle, attendance, finance, transport billing, expense durability controls, and polling-based in-app notifications.

## Current Status (as of February 22, 2026)

Implemented and active:
- Authentication via Laravel Sanctum (`/api/v1/login`, logout, token revocation)
- Role-based and module-based middleware authorization
- In-app notifications with a per-user database-backed feed under `/api/v1/notifications/*`
- Angular bell badge, recent dropdown, and full notification page backed by polling
- Student lifecycle management (CRUD, avatar, academic history, financial summary)
- Enrollment workflows (create, update, promote, repeat, transfer)
- Attendance workflows
- Marking, section/student views, lock flow, section statistics
- Report search and live search
- Monthly/session reports + bulk monthly exports
- Teacher attendance and self-attendance actions now surface to super admin/school admin via in-app notifications
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
- Library APIs remain commented/scaffolded and are not fully active.

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
- Header bell now shows unread in-app notifications with recent items
- `/notifications` page supports mark-read and mark-all-read flows
- Super admin dashboard notification area reflects teacher attendance activity

## Local Dev Commands

- Laravel + queue + Vite:
```bash
composer run dev
```

- Run tests:
```bash
composer test:preflight
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

- Run the dedicated email queue worker:
```powershell
composer queue:emails
```

- Run the email worker directly:
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\start-email-worker.ps1
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

Recommended first step on any new machine:

```bash
composer test:preflight
```

That command tells you whether the machine can run the full suite via:
- in-memory SQLite
- explicit `TEST_DB_*` settings
- MySQL fallback

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
- Notifications: `GET /api/v1/notifications`, `GET /api/v1/notifications/unread-count`, `GET /api/v1/notifications/recent`, `POST /api/v1/notifications/{id}/read`, `POST /api/v1/notifications/mark-all-read`
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
- In-app notifications are implemented using DB + REST + polling. No WebSockets/Reverb are required.
- Local or production environments must apply the `user_notifications` migration before testing the bell or notification page.
- Finance receipt/PDF flows include recent reliability fixes (logo fallback/data fallback).
- Email notifications are queued on the `emails` queue. For registration, update, admit, result, payment, and profile-PDF emails to send reliably, keep an email queue worker running continuously.

Reference docs:
- `QUICKSTART.md`
- `USER_MANUAL.md`
- `PROJECT_SUMMARY.md`
- `DATABASE_SCHEMA.md`
- `DURABILITY_STANDARD.md`

## Version

- Last updated: February 22, 2026
- Baseline: Laravel 12 + Sanctum API backend with finance, expense durability, and transport-ledger integration
