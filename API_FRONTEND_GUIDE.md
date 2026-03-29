# REST API Frontend Guide

This document is for frontend developers integrating with the Laravel backend in this repository.

Goal: make the API easy to consume without long back-and-forth clarification.

## 1. Base URL

- API version prefix: `/api/v1`
- Frontend environment currently points to: `https://api.ipsyogapatti.com/api/v1`
- In Angular, requests are made through `frontend/src/app/core/services/api-client.service.ts`

Example:

```ts
GET https://api.ipsyogapatti.com/api/v1/students
```

## 2. Authentication

Authentication uses Laravel Sanctum personal access tokens, but from the frontend you can treat it like normal Bearer-token auth.

### Login

- Method: `POST`
- Path: `/login`
- Body:

```json
{
  "email": "admin@example.com",
  "password": "secret"
}
```

- Success response:

```json
{
  "token": "plain-text-token",
  "token_type": "Bearer",
  "expires_at": "2026-04-27 14:00:00",
  "user": {
    "id": 1,
    "email": "admin@example.com",
    "role": "school_admin",
    "roles": ["school_admin"],
    "first_name": "Admin",
    "last_name": "User",
    "avatar": null,
    "avatar_url": null,
    "full_name": "Admin User",
    "status": "active"
  }
}
```

### Auth header

Send this on every protected request:

```http
Authorization: Bearer <token>
```

### Auth endpoints

- `POST /login`
- `POST /forgot-password`
- `POST /reset-password`
- `POST /logout`
- `POST /revoke-all-tokens`
- `GET /user`

### Frontend behavior

- Store `token`, `user`, and `expires_at`
- If any request returns `401`, clear session and redirect to login
- This is already how the Angular frontend works in `auth.interceptor.ts`

## 3. Common API Rules

### JSON by default

Most endpoints return JSON.

### File download endpoints

Some endpoints return:

- `blob` for PDF/Excel/file downloads
- `text/html` for printable receipt HTML

Examples:

- `GET /students/{id}/pdf`
- `GET /attendance/reports/monthly/download`
- `GET /finance/payments/{id}/receipt-html`

### Pagination shape

List endpoints usually return:

```json
{
  "data": [],
  "current_page": 1,
  "last_page": 1,
  "per_page": 15,
  "total": 0
}
```

### Validation errors

Laravel validation errors usually come as:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": ["Validation message"]
  }
}
```

### Permission errors

- `401` = not logged in / invalid token
- `403` = logged in but role or permission does not allow action

Frontend should not assume every logged-in user can open every page. Hide or disable actions based on role/permission-aware UI.

### Multipart/FormData

Use `FormData` for file uploads.

For some update endpoints with files, the frontend sends:

```txt
_method=PUT
```

This is already used by:

- student update
- school details update

### Dates

Use `YYYY-MM-DD` for date filters and most payload dates unless the field explicitly expects datetime.

## 4. Quick Integration Conventions

Before building a screen, follow this pattern:

1. Load required dropdown data first.
2. Submit IDs, not display names.
3. Expect optional nested objects in detail responses.
4. For list pages, support server-side pagination and filters.
5. For reports and printable assets, call dedicated download endpoints instead of rebuilding documents in the frontend.

## 4.1 Authorization expectations

The backend is not only auth-based, it is permission-based.

- `401` means the user is not authenticated or the token is invalid/expired
- `403` means the user is logged in but does not have permission for that module/action

Frontend should not assume that a logged-in user can:

- open every page
- use every action button on a page
- see every download/export action

Practical rule:

1. use route guards for broad access control
2. still handle `403` inside the page
3. hide admin-only actions when the UI already knows the user should not have them

## 4.2 ID usage rule that avoids most frontend confusion

This project uses both `student_id` and `enrollment_id`, and they are not interchangeable.

Use `student_id` for:

- student profile
- student detail
- student avatar/PDF
- some attendance/report search flows

Use `enrollment_id` for:

- fee assignment
- transport assignment
- ledger and balance flows tied to academic placement
- installment assignment
- many finance operations

Simple rule:

- if the feature depends on class, academic year, fees, transport, or academic session context, prefer `enrollment_id`

## 4.3 Response pattern rule

The API does not use one single response shape for every route.

Common patterns are:

- direct resource object
- `{ message, data }`
- paginated response
- blob/text download response

Frontend should not build one rigid generic mapper that assumes all endpoints return `{ data: ... }`.

## 4.4 File and form rule

Use `FormData` for:

- image upload
- signature upload
- teacher/employee document upload
- student update when a file is included

For some update endpoints, the existing frontend sends:

```txt
_method=PUT
```

That is already used in the Angular service layer for multipart updates.

## 4.5 Best source of truth rule

If there is any conflict between UI mockup assumptions and actual backend behavior, verify in this order:

1. `routes/api.php`
2. `frontend/src/app/core/services/*.ts`
3. `frontend/src/app/models/*.ts`
4. controller implementation if needed

## 5. Main Endpoint Reference

This section focuses on the endpoints a frontend developer is most likely to use.

## 5.1 Dashboard

### Endpoints

- `GET /dashboard/super-admin`
- `GET /dashboard/school-admin`
- `GET /dashboard/student`
- `GET /dashboard/notifications`
- `GET /dashboard/self-attendance/status`
- `POST /dashboard/self-attendance/mark`

### Notes

- Different dashboards are role-based.
- Student dashboard is a dedicated endpoint and should not be assembled manually from many other APIs unless necessary.
- The Angular super admin dashboard now shows recent in-app notifications instead of relying on the older dashboard broadcast feed for day-to-day activity.
- Teacher attendance marking and self-attendance actions are surfaced to super admin/school admin as recent in-app notifications.

## 5.1A In-App Notifications

### Endpoints

- `GET /notifications`
- `GET /notifications/unread-count`
- `GET /notifications/recent`
- `POST /notifications/{id}/read`
- `POST /notifications/mark-all-read`

### List query params

- `page`
- `per_page`
- `type`
- `status=read|unread|all`
- `limit` for `GET /notifications/recent`

### Notification item shape

```json
{
  "id": 15,
  "title": "Student attendance marked",
  "message": "Teacher marked student attendance for 32 record(s) on 2026-03-28.",
  "type": "attendance",
  "priority": "important",
  "entity_type": "attendance_mark",
  "entity_id": null,
  "action_target": "/attendance",
  "is_read": false,
  "read_at": null,
  "created_at": "2026-03-28T10:30:00+00:00",
  "meta": {
    "marked_by_user_id": 22,
    "marked_count": 32,
    "attendance_date": "2026-03-28"
  }
}
```

### Frontend notes

- Use `unread-count` for the bell badge and `recent` for the header dropdown.
- Poll every ~20 seconds on shared hosting instead of using sockets.
- Use `action_target` for click-through routing.
- Use `GET /notifications` for the full notifications page.

### Angular integration guide

Current frontend integration points:

- `frontend/src/app/core/services/notifications.service.ts`
- `frontend/src/app/layout/app-shell.component.ts`
- `frontend/src/app/features/notifications/notifications-page.component.ts`
- `frontend/src/app/models/notification.ts`

Recommended wiring:

1. call `fetchUnreadCount()` for the bell badge total
2. call `fetchRecent(limit)` for the header dropdown list
3. call `list({ page, per_page, status })` for the full notifications page
4. call `markRead(id)` before route navigation when the user opens one unread item
5. call `markAllRead()` from the dropdown or full page bulk action

Suggested polling flow:

1. on shell load, call `refreshBellState(5)` or `refreshBellState(6)`
2. repeat about every 20 seconds
3. refresh again after successful actions that create notifications if the current screen needs immediate visual feedback

Practical UI behavior:

- open `action_target` when present; otherwise keep the user on `/notifications`
- show unread styling from `is_read`
- keep the dropdown lightweight by using `recent` instead of the paginated list API
- use the full page for filters like `status=unread` and larger history browsing

### Example request patterns

Bell badge:

```http
GET /api/v1/notifications/unread-count
Authorization: Bearer <token>
```

Recent dropdown:

```http
GET /api/v1/notifications/recent?limit=6
Authorization: Bearer <token>
```

Full page with unread filter:

```http
GET /api/v1/notifications?page=1&per_page=20&status=unread
Authorization: Bearer <token>
```

## 5.2 Students

### Endpoints

- `GET /students`
- `POST /students`
- `GET /students/logo`
- `GET /students/{id}`
- `PUT /students/{id}`
- `DELETE /students/{id}`
- `GET /students/{id}/pdf`
- `GET /students/{id}/avatar`
- `GET /students/{id}/academic-history`
- `GET /students/{id}/financial-summary`

### List query params

- `status`
- `class_id`
- `section_id`
- `search`
- `per_page`
- `page`

### Common student response fields

```json
{
  "id": 10,
  "admission_number": "ADM-001",
  "admission_date": "2026-01-10",
  "date_of_birth": "2012-08-21",
  "gender": "male",
  "status": "active",
  "user": {
    "id": 55,
    "first_name": "Rahul",
    "last_name": "Kumar"
  },
  "currentEnrollment": {
    "id": 120,
    "class_id": 8,
    "status": "active"
  },
  "profile": {
    "avatar_url": null,
    "father_name": "Anil Kumar",
    "mother_name": "Sunita Devi"
  }
}
```

### Frontend notes

- Create/update may use JSON or `FormData`
- Student detail may include nested `user`, `currentEnrollment`, `latestEnrollment`, `profile`
- Use the backend PDF endpoint for printable student profile

## 5.3 School Details, Credentials, Signatures

### Endpoints

- `GET /school/details`
- `PUT /school/details`
- `GET /school/credentials`
- `GET /school/credentials/status`
- `PUT /school/credentials`
- `POST /school/credentials/test`
- `GET /school/signatures`
- `POST /school/signatures`
- `DELETE /school/signatures/{slot}`

### Notes

- `slot` is `principal` or `director`
- `school/details` may use `FormData` when uploading logo/watermark assets
- `school/credentials/test` expects:

```json
{
  "test_email": "dev@example.com"
}
```

## 5.4 Message Center

### Endpoints

- `POST /message-center/send`
- `GET /message-center/status/{batchId}`
- `GET /message-center/birthday-settings`
- `PUT /message-center/birthday-settings`

### Send message payload

```json
{
  "language": "english",
  "channel": "email",
  "audience": "parents",
  "subject": "Fee Reminder",
  "message": "Please clear due fees.",
  "student_ids": [1, 2, 3],
  "schedule_at": null
}
```

### Notes

- `channel`: `email | sms | whatsapp`
- `audience`: `students | parents | both`
- `schedule_at` is optional for scheduled sends

## 5.5 Events

### Endpoints

- `GET /events`
- `POST /events`
- `GET /events/{id}`
- `PUT /events/{id}`
- `DELETE /events/{id}`
- `PUT /events/{id}/participants`
- `GET /events/participants/{participantId}/certificate`

### List query params

- `academic_year_id`
- `search`
- `page`
- `per_page`

### Notes

- Certificate endpoint is a file download
- Certificate query param: `type=participant` or `type=winner`

## 5.6 Teachers, Employees, HR/Payroll

### Teacher endpoints

- `GET /teachers`
- `POST /teachers`
- `GET /teachers/{id}`
- `PUT /teachers/{id}`
- `DELETE /teachers/{id}`
- `POST /teachers/{id}/documents`
- `GET /teachers/{id}/documents/{documentId}/file`

### Employee endpoints

- `GET /employees/metadata`
- `GET /employees`
- `POST /employees`
- `GET /employees/{id}`
- `PUT /employees/{id}`
- `DELETE /employees/{id}`
- `POST /employees/{id}/documents`
- `GET /employees/{id}/documents/{documentId}/file`
- `GET /employees/{id}/attendance-history`
- `GET /employees/{id}/attendance-history/download`
- `GET /employees/{id}/payout-history`
- `GET /employees/{id}/payout-history/download`

### HR + payroll endpoints

- `POST /hr/attendance/mark`
- `POST /hr/attendance/lock-month`
- `POST /hr/attendance/unlock-month`
- `GET /hr/attendance/selfie-daily`
- `POST /hr/attendance/selfie/{sessionId}/approve`
- `GET /hr/leave/types`
- `GET /hr/leave/requests`
- `POST /hr/leave/requests`
- `POST /hr/leave/requests/{leaveId}/decision`
- `POST /hr/leave/ledger`
- `GET /hr/leave/balance/{staffId}`
- `GET /hr/salary/templates`
- `POST /hr/salary/templates`
- `POST /hr/salary/assignments`
- `GET /hr/payroll`
- `GET /hr/payroll/period-options`
- `POST /hr/payroll/generate`
- `POST /hr/payroll/{batchId}/finalize`
- `POST /hr/payroll/{batchId}/mark-paid`
- `POST /hr/payroll/{batchId}/items/{itemId}/adjustments`
- `GET /hr/payroll/{batchId}`

### Frontend notes

- Document upload endpoints require `FormData`
- Download endpoints return `blob`
- Payroll flow is multi-step: generate -> review -> finalize -> mark paid

## 5.7 Academic Structure

### Academic years

- `GET /academic-years/current`
- `GET /academic-years`
- `POST /academic-years`
- `GET /academic-years/{id}`
- `PUT /academic-years/{id}`
- `DELETE /academic-years/{id}`
- `POST /academic-years/{id}/set-current`
- `POST /academic-years/{id}/close`

### Exam configurations

- `GET /exam-configurations`
- `POST /exam-configurations`
- `PUT /exam-configurations/{id}`
- `DELETE /exam-configurations/{id}`

### Classes

- `GET /classes`
- `POST /classes`
- `GET /classes/{id}`
- `PUT /classes/{id}`
- `DELETE /classes/{id}`

### Sections

- `GET /sections`
- `POST /sections`
- `GET /sections/{id}`
- `PUT /sections/{id}`
- `DELETE /sections/{id}`

### Subjects

- `GET /subjects`
- `POST /subjects`
- `GET /subjects/{id}`
- `PUT /subjects/{id}`
- `DELETE /subjects/{id}`
- `POST /subjects/{id}/class-mappings`
- `DELETE /subjects/{id}/class-mappings/{classId}/{academicYearId}`
- `GET /subjects/{id}/teacher-assignments`
- `POST /subjects/{id}/teacher-assignments`
- `DELETE /subjects/{id}/teacher-assignments/{assignmentId}`

### Frontend notes

- Use IDs from academic year, class, section, subject dropdowns
- Subject assignment screens should treat mappings and teacher assignments as separate resources

## 5.8 Enrollments

### Endpoints

- `GET /enrollments`
- `POST /enrollments`
- `GET /enrollments/{id}`
- `PUT /enrollments/{id}`
- `GET /enrollments/{id}/academic-history`
- `POST /enrollments/{id}/promote`
- `POST /enrollments/{id}/repeat`
- `POST /enrollments/{id}/transfer`

### Frontend notes

- Enrollment is the academic record anchor used heavily across attendance, fees, transport, results, and admits
- Prefer `enrollment_id` whenever the finance or transport flow asks for it

## 5.9 Attendance

### Endpoints

- `POST /attendance/mark`
- `GET /attendance/section`
- `GET /attendance/student/{studentId}`
- `GET /attendance/section/statistics`
- `GET /attendance/reports/search`
- `GET /attendance/reports/live-search`
- `GET /attendance/reports/monthly/download`
- `GET /attendance/reports/session/download`
- `GET /attendance/reports/bulk/monthly`
- `GET /attendance/reports/bulk/monthly/download`
- `POST /attendance/lock`

### Common payload

```json
{
  "class_id": 8,
  "section_id": 2,
  "date": "2026-03-28",
  "attendances": [
    {
      "student_id": 101,
      "status": "present"
    },
    {
      "student_id": 102,
      "status": "absent"
    }
  ]
}
```

### Common query params

- Section attendance: `class_id`, `section_id`, `date`
- Student summary: `academic_year_id`, `start_date`, `end_date`
- Statistics: `class_id`, `section_id`, `start_date`, `end_date`
- Report live search: `q`, `academic_year_id`, `class_ids`, `month`

### Notes

- Reporting and export endpoints should be used directly; do not try to rebuild monthly sheets from raw attendance unless necessary
- Super admin and school admin users can see teacher attendance actions through the in-app notification system after attendance is saved successfully.

## 5.10 Timetable

### Admin/system endpoints

- `GET /timetable/time-slots`
- `POST /timetable/time-slots`
- `PUT /timetable/time-slots/{id}`
- `DELETE /timetable/time-slots/{id}`
- `GET /timetable/section`
- `GET /timetable/section/download`
- `POST /timetable/section`

### Student endpoints

- `GET /timetable/student/me`
- `GET /timetable/student/me/download`

### Teacher academic endpoints

- `GET /teacher-academics/assignments`
- `GET /teacher-academics/timetable`
- `GET /teacher-academics/attendance-sheet`
- `POST /teacher-academics/attendance`
- `GET /teacher-academics/marks-sheet`
- `POST /teacher-academics/marks`

### Save section timetable payload

```json
{
  "academic_year_id": 1,
  "section_id": 2,
  "entries": [
    {
      "day_of_week": "monday",
      "time_slot_id": 4,
      "subject_id": 7,
      "teacher_id": 12,
      "room_number": "A-12"
    }
  ]
}
```

## 5.11 Results

### Public endpoint

- `GET /public/results/verify`

### Admin marks endpoints

- `GET /admin-marks/filters`
- `GET /admin-marks/sheet`
- `POST /admin-marks/compile`
- `POST /admin-marks/finalize`

### Result publishing endpoints

- `GET /results/published/sessions`
- `GET /results/sessions`
- `POST /results/sessions`
- `GET /results/published`
- `GET /results/{studentResultId}/paper`
- `POST /results/publish`
- `POST /results/publish/class-wise`
- `POST /results/sessions/{sessionId}/lock`
- `POST /results/sessions/{sessionId}/unlock`
- `POST /results/{studentResultId}/visibility`
- `POST /results/{studentResultId}/verification/revoke`

### Published result list filters

- `exam_session_id`
- `class_id`
- `search`
- `per_page`
- `page`

### Result paper response structure

- `school`
- `result_paper`
- `result_paper.subjects[]`
- `result_paper.qr_verify_url`

### Notes

- Use `GET /results/{studentResultId}/paper` for the final printable view data
- Visibility states used in frontend:
  - `visible`
  - `withheld`
  - `under_review`
  - `disciplinary_hold`

## 5.12 Admit Cards

### Public endpoint

- `GET /public/admits/verify`

### Endpoints

- `GET /admits/me`
- `GET /admits/{admitCardId}/paper`
- `GET /admits/sessions`
- `GET /admits/sessions/{sessionId}/cards`
- `GET /admits/sessions/{sessionId}/paper`
- `GET /admits/{admitCardId}/paper/download`
- `POST /admits/generate`
- `POST /admits/sessions/{sessionId}/publish`
- `POST /admits/{admitCardId}/visibility`

### Generate admit payload

```json
{
  "exam_session_id": 15,
  "reason": "First publish",
  "center_name": "IPS Main Campus",
  "seat_prefix": "A",
  "schedule": {
    "subjects": [
      {
        "subject_id": 1,
        "subject_name": "Mathematics",
        "exam_date": "2026-04-04",
        "exam_shift": "1st Shift",
        "start_time": "09:00",
        "end_time": "12:00",
        "room_number": "Hall 1",
        "max_marks": 100
      }
    ]
  }
}
```

### Notes

- `GET /admits/{id}/paper` returns structured JSON for preview
- `GET /admits/{id}/paper/download` returns the actual PDF/blob
- Session paper endpoint is bulk download

## 5.13 Transport

### Endpoints

- `GET /transport/routes`
- `POST /transport/routes`
- `GET /transport/stops`
- `POST /transport/stops`
- `POST /transport/assignments`
- `POST /transport/assignments/bulk`
- `GET /transport/assignments`
- `POST /transport/assignments/{id}/stop`
- `POST /transport/fee-cycles/generate`

### Notes

- Transport uses `enrollment_id` heavily
- Stop listing supports `route_id`
- Bulk assignment response contains per-enrollment result rows

## 5.14 Finance

Finance routes are under `/finance/*`.

### Master data

- `GET /finance/fee-structures`
- `POST /finance/fee-structures`
- `GET /finance/fee-structures/{id}`
- `PUT /finance/fee-structures/{id}`
- `GET /finance/fee-heads`
- `POST /finance/fee-heads`
- `PUT /finance/fee-heads/{id}`
- `GET /finance/installments`
- `POST /finance/installments`
- `PUT /finance/installments/{id}`
- `GET /finance/optional-services`
- `POST /finance/optional-services`
- `PUT /finance/optional-services/{id}`
- `GET /finance/hostel-fees`
- `POST /finance/hostel-fees`
- `PUT /finance/hostel-fees/{id}`

### Assignment and installment operations

- `GET /finance/fee-assignments/enrollment/{id}`
- `GET /finance/fee-assignments/{id}/summary`
- `POST /finance/fee-assignments/enrollment/{id}/discount`
- `POST /finance/fee-assignments/enrollment/{id}`
- `POST /finance/students/{id}/installments`
- `POST /finance/enrollments/{id}/installments`
- `POST /finance/installments/assign-to-class`
- `GET /finance/students/{id}/installments`

### Payments, receipts, ledger

- `POST /finance/payments`
- `GET /finance/payments/enrollment/{id}`
- `GET /finance/payments/{id}/receipt`
- `GET /finance/payments/{id}/receipt-html`
- `POST /finance/payments/{id}/refund`
- `GET /finance/payments/enrollment/{id}/receipt`
- `POST /finance/receipts`
- `GET /finance/students/{id}/ledger`
- `GET /finance/students/{id}/ledger/download`
- `GET /finance/classes/{id}/ledger`
- `GET /finance/classes/{id}/ledger/statements`
- `GET /finance/classes/{id}/ledger/download`
- `GET /finance/enrollments/{id}/ledger`
- `GET /finance/students/{id}/balance`
- `GET /finance/enrollments/{id}/balance`
- `POST /finance/ledger/{id}/reverse`
- `POST /finance/enrollments/{id}/special-fee`

### Holds and reports

- `GET /finance/holds`
- `POST /finance/holds`
- `PUT /finance/holds/{id}`
- `GET /finance/reports/fees/due`
- `GET /finance/reports/fees/collection`
- `GET /finance/reports/transport/route-wise`

### Expenses

- `GET /finance/expenses`
- `POST /finance/expenses`
- `POST /finance/expenses/{id}/reverse`
- `GET /finance/reports/expenses/audit`
- `GET /finance/reports/expenses/entries/download`
- `GET /finance/expenses/receipts/{id}/file`
- `POST /finance/expenses/{id}/receipts`

### Common finance objects

- payment
- receipt
- ledger entry
- class ledger summary
- student balance
- due report
- collection report

### Frontend notes

- Finance is enrollment-centric, not only student-centric
- Use receipt HTML endpoint for print preview
- Use dedicated ledger/report endpoints instead of calculating totals on the client
- Refunds and reversals have dedicated actions; do not simulate them with negative manual entries from the UI

### Finance mental model for frontend

Treat finance as 5 separate sub-modules, not one big screen:

1. master data
2. assignments/installments
3. payments/receipts
4. ledger/balances
5. reports/expenses

This prevents most implementation mistakes.

### 1. Master data

Used to configure what can later be assigned/charged:

- fee structures
- fee heads
- installments
- optional services
- hostel fees

These are mostly admin/configuration screens.

### 2. Assignments/installments

Used to decide what a student/enrollment should be charged.

Important endpoints:

- `GET /finance/fee-assignments/enrollment/{id}`
- `POST /finance/fee-assignments/enrollment/{id}`
- `POST /finance/fee-assignments/enrollment/{id}/discount`
- `POST /finance/students/{id}/installments`
- `POST /finance/enrollments/{id}/installments`
- `POST /finance/installments/assign-to-class`

Frontend should treat discounts as their own business action. They are not normal edits to payment rows.

### 3. Payments/receipts

Used when money is collected or refunded.

Important endpoints:

- `POST /finance/payments`
- `GET /finance/payments/enrollment/{id}`
- `GET /finance/payments/{id}/receipt`
- `GET /finance/payments/{id}/receipt-html`
- `POST /finance/payments/{id}/refund`

Important payment fields already used by frontend models:

- `receipt_number`
- `amount`
- `payment_date`
- `payment_method`
- `transaction_id`
- `remarks`
- `is_refunded`

Allowed payment methods from frontend models:

- `cash`
- `cheque`
- `online`
- `card`
- `upi`

### 4. Ledger/balances

Used for financial history and the actual due/credit position.

Important endpoints:

- `GET /finance/students/{id}/ledger`
- `GET /finance/enrollments/{id}/ledger`
- `GET /finance/students/{id}/balance`
- `GET /finance/enrollments/{id}/balance`
- `POST /finance/ledger/{id}/reverse`
- `POST /finance/enrollments/{id}/special-fee`

If the page needs running balance, debit/credit history, or printable statement, use ledger APIs. Do not try to derive ledger from payments only.

### 5. Reports/expenses

Used for management and accounting views.

Important endpoints:

- `GET /finance/reports/fees/due`
- `GET /finance/reports/fees/collection`
- `GET /finance/reports/transport/route-wise`
- `GET /finance/reports/expenses/audit`
- `GET /finance/reports/expenses/entries/download`
- `GET /finance/expenses`
- `POST /finance/expenses`
- `POST /finance/expenses/{id}/reverse`

### Student finance page recommended load order

For a student/enrollment finance screen, use this order:

1. student or enrollment detail
2. assignment/installment data
3. ledger
4. balance
5. payment history
6. receipt preview/download actions

### Important frontend warning for finance

Do not mix these concepts:

- discount
- refund
- reversal
- special fee
- payment
- ledger entry

They look similar in UI language, but they are separate backend actions.

## 5.15 Audit Downloads

### Endpoints

- `GET /audit-downloads/catalog`
- `GET /audit-downloads/logs`
- `GET /audit-downloads/logs/export`
- `GET /audit-downloads/logs/archive`
- `POST /audit-downloads/logs`

### Notes

- Use this module when tracking downloadable reports or exports
- Export and archive endpoints return files

## 6. Screen-by-Screen Recommended API Usage

This section is intentionally practical. It explains which API calls a frontend screen should usually make first, second, and third.

## 6.1 Student list page

Recommended calls:

1. `GET /classes` if page has class filter
2. `GET /sections?class_id=...` if page has section filter
3. `GET /students`

Recommended filters:

- `search`
- `status`
- `class_id`
- `section_id`
- `page`
- `per_page`

## 6.2 Student detail page

Recommended calls:

1. `GET /students/{id}`
2. `GET /students/{id}/academic-history`
3. `GET /students/{id}/financial-summary`
4. optional `GET /students/{id}/avatar`
5. optional `GET /students/{id}/pdf`

Use this page for profile + summary data. Do not load full finance ledger here unless the UI really needs it.

## 6.3 Enrollment management page

Recommended calls:

1. `GET /academic-years`
2. `GET /classes`
3. `GET /sections`
4. `GET /enrollments`

For row actions:

- promote -> `POST /enrollments/{id}/promote`
- repeat -> `POST /enrollments/{id}/repeat`
- transfer -> `POST /enrollments/{id}/transfer`

These should use confirmation modals because they are academic transition actions, not simple edits.

## 6.4 Attendance marking page

Recommended calls:

1. `GET /classes`
2. `GET /sections?class_id=...`
3. `GET /attendance/section`
4. `POST /attendance/mark`
5. optional `POST /attendance/lock`

Practical rule:

- always fetch the attendance sheet for the selected date before submit
- submit only student IDs and statuses in `attendances[]`

## 6.5 Timetable admin page

Recommended calls:

1. `GET /timetable/time-slots`
2. `GET /academic-years`
3. `GET /sections`
4. `GET /timetable/section`
5. `POST /timetable/section`
6. optional `GET /timetable/section/download`

Use `matrix` from the response for grid UI. Use `rows` if building an editor panel.

## 6.6 Subject assignment page

Recommended calls:

1. `GET /subjects`
2. `GET /teachers`
3. `GET /academic-years`
4. `GET /exam-configurations`
5. `GET /subjects/{id}/teacher-assignments`
6. `POST /subjects/{id}/teacher-assignments`

Keep these as separate UI sections:

- subject details
- class mappings
- teacher assignments

## 6.7 Result publishing page

Recommended calls:

1. `GET /academic-years`
2. `GET /classes`
3. `GET /results/sessions`
4. `GET /results/published`
5. `GET /results/{studentResultId}/paper`

Administrative actions:

- publish -> `POST /results/publish` or `POST /results/publish/class-wise`
- lock -> `POST /results/sessions/{sessionId}/lock`
- unlock -> `POST /results/sessions/{sessionId}/unlock`
- visibility -> `POST /results/{studentResultId}/visibility`

## 6.8 Admit generation page

Recommended calls:

1. `GET /academic-years`
2. `GET /classes`
3. `GET /exam-configurations`
4. `GET /admits/sessions`
5. `GET /admits/sessions/{sessionId}/cards`
6. `POST /admits/generate`
7. `POST /admits/sessions/{sessionId}/publish`
8. preview/download endpoints as needed

Good UI split:

- session filter
- generation form
- generated cards list
- publish action
- preview/download action

## 6.9 Student finance page

Recommended calls:

1. `GET /students/{id}` or enrollment detail if screen is enrollment-first
2. `GET /finance/fee-assignments/enrollment/{id}`
3. `GET /finance/enrollments/{id}/ledger` or `GET /finance/students/{id}/ledger`
4. `GET /finance/enrollments/{id}/balance` or `GET /finance/students/{id}/balance`
5. `GET /finance/payments/enrollment/{id}`

For receipt preview/printing:

- `GET /finance/payments/{id}/receipt`
- `GET /finance/payments/{id}/receipt-html`

## 6.10 Employee detail page

Recommended calls:

1. `GET /employees/{id}`
2. `GET /employees/{id}/attendance-history`
3. `GET /employees/{id}/payout-history`
4. optional document download endpoints

Best tab structure:

- profile
- documents
- attendance history
- payout history

## 6.11 Message center page

Recommended calls:

1. recipient search/list from student APIs
2. `GET /message-center/birthday-settings` for settings section
3. `POST /message-center/send`
4. if queued, poll `GET /message-center/status/{batchId}`

## 6.12 Notification UX

Recommended calls:

1. `GET /notifications/unread-count`
2. `GET /notifications/recent`
3. `GET /notifications`
4. `POST /notifications/{id}/read`
5. `POST /notifications/mark-all-read`

Recommended frontend behavior:

1. poll bell state every ~20 seconds
2. use `recent` for dropdowns, not full paginated history
3. use `action_target` for route navigation
4. keep notifications additive; do not make them the only source of truth

## 7. Common Frontend Mistakes to Avoid

- Do not use `student_id` where the finance/transport flow needs `enrollment_id`
- Do not calculate ledger totals from payments only
- Do not rebuild PDFs or report exports in the frontend if a download endpoint already exists
- Do not assume every successful response is wrapped in `{ data: ... }`
- Do not assume list-row fields are identical to full detail fields
- Do not assume `403` means the app is broken; it often means the user lacks permission
- Do not send JSON when the endpoint expects file upload or multipart update
- Do not hide action confirmations for publish, refund, reverse, transfer, promote, lock, or visibility changes

## 6. Recommended Frontend Screen Flow

To avoid misunderstandings, use these dependency rules:

### Student finance screen

Load in this order:

1. student detail or enrollment detail
2. ledger
3. balance
4. payments
5. transport charge if applicable

### Attendance screen

Load in this order:

1. classes
2. sections for selected class
3. section attendance for selected date
4. submit mark payload with student IDs only

### Result publishing screen

Load in this order:

1. academic year
2. class
3. exam session
4. published list or result paper

### Admit generation screen

Load in this order:

1. academic year
2. class
3. exam configuration/session
4. schedule subject rows
5. generate
6. publish

## 7. What the Frontend Should Not Assume

- Do not assume every response shape is flat; many detail responses include nested objects
- Do not assume every authenticated user can access every route
- Do not assume PDF/print data should be rendered from raw list endpoints
- Do not assume `student_id` can replace `enrollment_id` in finance and transport flows
- Do not assume update requests with files can be sent as normal JSON

## 8. Best Source of Truth in This Repo

If a developer wants implementation examples, use these files first:

- `routes/api.php`
- `frontend/src/app/core/services/*.ts`
- `frontend/src/app/models/*.ts`

Most of the frontend request and response expectations are already encoded there.

## 9. Suggested Handoff Note for Any Frontend Developer

Use `/api/v1` as the base API prefix, authenticate with Bearer token from `/login`, treat `enrollment_id` as the main key for finance/transport/results flows, use dedicated download endpoints for PDFs/Excel/printable HTML, and expect Laravel-standard `401`, `403`, and `422` responses. If you follow the Angular service files in `frontend/src/app/core/services`, your request shapes should stay aligned with the backend.
