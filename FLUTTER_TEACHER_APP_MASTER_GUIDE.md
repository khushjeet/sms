# Flutter Teacher App Master Guide

## Purpose

This document is the single combined reference for the Flutter teacher app in this SMS system.

It combines:

- developer guide
- scalable architecture guidance
- iOS-style design-system guidance
- teacher app API contract

This app must be:

- aligned with the current Laravel backend
- scalable to 30 to 70+ feature areas
- durable for 20 to 30 years
- safe to evolve without breaking stable workflows

## System Alignment

The Flutter app must follow the current backend behavior exactly.

Backend facts from this repository:

- API base prefix is `/api/v1`
- authentication uses Laravel Sanctum Bearer tokens
- teacher workflows already exist under `/teacher-academics/*`
- notifications exist under `/notifications/*`
- password reset APIs already exist

Primary backend references:

- `routes/api.php`
- `app/Http/Controllers/Api/AuthController.php`
- `app/Http/Controllers/Api/TeacherAcademicController.php`
- `app/Http/Controllers/Api/TimetableController.php`
- `app/Http/Controllers/Api/NotificationController.php`
- `API_FRONTEND_GUIDE.md`

## Product Scope

Phase 1 supported teacher workflows:

1. Login
2. Restore session
3. Logout
4. Password reset request
5. Fetch authenticated profile
6. View teacher assignments
7. View teacher timetable
8. Load attendance sheet
9. Save attendance
10. Load marks sheet
11. Save marks
12. View notifications
13. Mark notification as read
14. Mark all notifications as read

Supported user type:

- `teacher`

Out of scope unless backend expands:

- admin dashboards
- finance and transport flows
- student CRUD
- offline-first write sync
- real-time sockets

## Durability Goal

This app is a long-life platform, not a short-term feature bundle.

Engineering target:

- support 30 to 70+ features over time
- remain maintainable for 20 to 30 years
- allow additive growth without breakage
- isolate change so new modules do not destabilize old ones

Required qualities:

- low coupling
- strong boundaries
- backward-compatible evolution
- stable contracts
- replaceable infrastructure
- testability
- migration safety

## Recommended Stack

- Dart
- Flutter
- Riverpod or Bloc
- Dio
- `json_serializable` or `freezed`
- `flutter_secure_storage`
- Hive or Drift
- `go_router`

## App and Module Architecture

Recommended top-level layout:

```text
lib/
|- app/
|- bootstrap/
|- core/
|- data/
|- domain/
|- features/
|- design_system/
```

Responsibilities:

- `app/`: shell, router, root DI, session bootstrap
- `bootstrap/`: environment and app startup wiring
- `core/`: cross-cutting technical services only
- `data/`: DTOs, persistence, repository implementations
- `domain/`: shared contracts and business primitives
- `features/`: bounded feature modules
- `design_system/`: tokens and reusable UI primitives

Recommended feature shape:

```text
features/
|- attendance/
|  |- presentation/
|  |- application/
|  |- domain/
|  |- data/
|  |- routes/
|  |- di/
```

Dependency direction:

- presentation -> application -> domain
- data -> domain
- app shell may depend on feature public entry points

Non-negotiable rules:

- no HTTP directly from widgets
- no raw JSON in presentation state
- no feature-private logic inside `core/`
- no hidden global mutable state
- no breaking storage changes without migrations

## Scalability Rules

To support 30 to 70+ features safely:

- each feature must be independently buildable and testable
- new features should be added as new modules, not by bloating existing ones
- DTOs, local entities, and domain models must stay separate
- repositories must expose app-facing contracts, not raw HTTP responses
- shared abstractions should move into common layers only after repeated proven need
- route names and local storage schemas must be versioned carefully
- feature flags should be available for risky or partial rollouts

Long-term growth path:

1. Add bounded feature module
2. Add contracts and mappings
3. Add tests
4. Integrate through app shell
5. Monitor and refine boundaries

If the app later becomes multi-role:

- keep one shared platform layer
- create separate bounded role feature groups
- do not mix teacher-only rules into shared infrastructure

## Data Layer Contract

Repository flow:

1. UI calls use case
2. Use case calls repository contract
3. Repository chooses remote/local source
4. Remote DTO maps to domain model
5. UI receives domain-safe models only

Rules:

- one API client configuration
- one shared error parsing strategy
- one shared retry policy strategy
- unknown JSON fields must not break parsing
- nullable backend fields must be handled safely

## Storage and Migration Rules

Use:

- `flutter_secure_storage` for tokens and session secrets
- Hive or Drift for cached entities

Rules:

- version every persisted schema
- write and test migrations
- keep per-feature cache ownership clear
- define retention/cleanup policy
- do not persist unnecessary raw backend blobs

## UI Direction

The app should be Flutter-based with an iOS-style visual language.

Principles:

- calm
- premium
- clean
- touch-friendly
- readable in data-heavy school workflows

Recommended patterns:

- Cupertino-inspired navigation
- large-title headers where useful
- segmented controls
- bottom sheets for quick actions
- clean cards and soft surfaces
- restrained color usage

The UI must still remain usable on Android devices even if the style direction is iOS-inspired.

## Design System

All reusable style values must come from tokens.

Token groups:

- color
- typography
- spacing
- radius
- border
- elevation
- opacity
- motion
- icon sizing

Recommended color semantics:

- `primary`
- `secondary`
- `accent`
- `surface`
- `surfaceMuted`
- `background`
- `textPrimary`
- `textSecondary`
- `success`
- `warning`
- `danger`
- `info`
- `divider`

Recommended typography levels:

- display
- title
- section title
- body
- body secondary
- caption
- data emphasis

Recommended reusable primitives:

- app scaffold
- top navigation bar
- section header
- buttons
- text field
- search field
- segmented control
- card container
- list row
- loading state
- empty state
- error state
- status badge
- confirmation dialog

Teacher workflow components should be built on these primitives:

- assignment card
- timetable slot card
- attendance row
- marks row
- notification row
- profile summary header

Durability rules:

- no hardcoded visual values in feature widgets
- no random one-off styling by feature
- statuses must use text plus color, not color alone
- responsive behavior must work across small phones and future tablets

## Authentication Model

### `POST /login`

Request:

```json
{
  "email": "teacher@school.com",
  "password": "password"
}
```

Success shape:

```json
{
  "token": "plain-text-token",
  "token_type": "Bearer",
  "expires_at": "2026-04-29 10:00:00",
  "user": {
    "id": 12,
    "email": "teacher@school.com",
    "role": "teacher",
    "roles": ["teacher"],
    "first_name": "Anita",
    "last_name": "Kumari",
    "avatar": "teachers/avatars/example.jpg",
    "avatar_url": "https://example.com/storage/teachers/avatars/example.jpg",
    "full_name": "Anita Kumari",
    "status": "active"
  }
}
```

App rules:

- save `token`, `expires_at`, and `user`
- send `Authorization: Bearer <token>` on protected requests
- allow teacher flows only for teacher role
- handle inactive-account or forbidden states cleanly

### `GET /user`

Use on startup to validate a stored session.

### `POST /logout`

Clear secure session state on success.

### `POST /revoke-all-tokens`

Optional for first release, useful later for account security.

### `POST /forgot-password`

Use for password reset initiation.

### `POST /reset-password`

Use for reset completion.

## Authorization Rules

The app must not treat itself as the final authority for permissions.

Backend authorization uses:

- `auth:sanctum`
- role checks
- permission checks
- module checks

Teacher-side expectations:

- only authenticated teacher users can access teacher flows
- teachers can only access their own assignments
- attendance and marks must remain inside allowed assignment scope
- exam configuration rules come from backend validation

## Teacher API Contract

### Core Endpoints

Auth:

- `POST /login`
- `GET /user`
- `POST /logout`
- `POST /revoke-all-tokens`
- `POST /forgot-password`
- `POST /reset-password`

Teacher academics:

- `GET /teacher-academics/assignments`
- `GET /teacher-academics/timetable`
- `GET /teacher-academics/attendance-sheet`
- `POST /teacher-academics/attendance`
- `GET /teacher-academics/marks-sheet`
- `POST /teacher-academics/marks`

Notifications:

- `GET /notifications`
- `GET /notifications/unread-count`
- `GET /notifications/recent`
- `POST /notifications/{id}/read`
- `POST /notifications/mark-all-read`

### Global Request Headers

```http
Authorization: Bearer <token>
Accept: application/json
Content-Type: application/json
```

### Global Error Expectations

- `200` success
- `401` unauthenticated or invalid token
- `403` authenticated but not allowed
- `422` validation or business-rule failure

The app should parse both field validation responses and plain `message` responses.

## Feature Contracts

### Assignments

Endpoint:

- `GET /teacher-academics/assignments`

Sample response:

```json
[
  {
    "id": 101,
    "subject_id": 7,
    "section_id": 2,
    "class_id": 4,
    "academic_year_id": 1,
    "subject_name": "Mathematics",
    "subject_code": "MATH",
    "section_name": "A",
    "class_name": "Class 8",
    "academic_year_name": "2026-27",
    "mapped_max_marks": 50,
    "mapped_pass_marks": 18,
    "mapped_exam_configuration_id": 4,
    "mapped_exam_configuration_name": "Periodic Test 1"
  }
]
```

Rules:

- `id` is the assignment key for attendance and marks flows
- `section_name` may be `All Sections`

### Timetable

Endpoint:

- `GET /teacher-academics/timetable`

Optional query:

- `academic_year_id`

Sample response:

```json
{
  "meta": {
    "teacher_id": 12,
    "teacher_name": "Anita Kumari",
    "academic_year_id": 1,
    "academic_year_name": "2026-27"
  },
  "academic_year_options": [
    {
      "id": 1,
      "name": "2026-27",
      "is_current": true
    }
  ],
  "days": [],
  "slots": [],
  "rows": [],
  "matrix": []
}
```

Rules:

- tolerate empty arrays
- use `matrix` for grid rendering if needed
- use `rows` for list rendering if needed

### Attendance Sheet

Endpoint:

- `GET /teacher-academics/attendance-sheet`

Query:

- `assignment_id` required
- `date` required

Sample response:

```json
[
  {
    "enrollment_id": 501,
    "student_id": 1001,
    "roll_number": 1,
    "student_name": "Student Name",
    "status": "present",
    "remarks": null,
    "is_locked": false
  }
]
```

Status values:

- `not_marked`
- `present`
- `absent`
- `leave`
- `half_day`

Rules:

- locked rows must be non-editable
- only fetched enrollments should be submitted back

### Save Attendance

Endpoint:

- `POST /teacher-academics/attendance`

Request:

```json
{
  "assignment_id": 101,
  "date": "2026-03-30",
  "attendances": [
    {
      "enrollment_id": 501,
      "status": "present",
      "remarks": null
    }
  ]
}
```

Success:

```json
{
  "message": "Attendance saved successfully."
}
```

Rules:

- `attendances` min 1 max 200
- invalid enrollment scope returns `422`
- locked rows are skipped by backend

### Marks Sheet

Endpoint:

- `GET /teacher-academics/marks-sheet`

Query:

- `assignment_id` required
- `exam_configuration_id` required
- `marked_on` optional

Sample response:

```json
{
  "marked_on": "2026-03-30",
  "exam_configuration_id": 4,
  "rows": [
    {
      "enrollment_id": 501,
      "student_id": 1001,
      "roll_number": 1,
      "student_name": "Student Name",
      "marks_obtained": 45,
      "max_marks": 50,
      "remarks": "Good"
    }
  ]
}
```

Rules:

- exam configuration must match assignment academic year
- exam configuration must be active
- mismatched mapped configurations may return `422`

### Save Marks

Endpoint:

- `POST /teacher-academics/marks`

Request:

```json
{
  "assignment_id": 101,
  "marked_on": "2026-03-30",
  "exam_configuration_id": 4,
  "marks": [
    {
      "enrollment_id": 501,
      "marks_obtained": 45,
      "max_marks": 50,
      "remarks": "Good"
    }
  ]
}
```

Success:

```json
{
  "message": "Marks saved successfully."
}
```

Rules:

- `marks` min 1 max 200
- `marks_obtained` may be null
- `max_marks` may be null and may default from assignment mapping
- if `marks_obtained > max_marks`, backend may skip that row
- app should validate before submit

### Notifications

Unread count:

- `GET /notifications/unread-count`

Recent:

- `GET /notifications/recent`

List:

- `GET /notifications`

Mark read:

- `POST /notifications/{id}/read`

Mark all read:

- `POST /notifications/mark-all-read`

Notification rendering rules:

- tolerate unknown `type` values
- tolerate nullable metadata
- fail gracefully when `action_target` does not map to a current app route

## Screen Map

1. Splash
2. Login
3. Forgot Password
4. Home Dashboard
5. My Assignments
6. My Timetable
7. Attendance Assignment Picker
8. Attendance Sheet
9. Marks Assignment Picker
10. Marks Sheet
11. Notifications
12. Profile / Session

Home dashboard can be assembled from existing endpoints rather than requiring a new backend dashboard endpoint.

## Local Data and Offline Rules

Cache these carefully:

- auth token
- session summary
- assignments
- timetable
- recent notifications

Phase 1 offline guidance:

- allow cached read-only views
- disable attendance and marks submission offline
- do not add hidden queued sync logic yet

If offline sync is introduced later, it should be a dedicated platform service with journals, retries, reconciliation, and conflict policy.

## Error Handling

### `401`

- clear session
- redirect to login

### `403`

- show teacher-access-required or unauthorized state
- keep user logged in unless session is invalid

### `422`

- show field or business-rule errors
- do not log user out

Shared error-model rule:

- use one app-wide error model for transport, auth, validation, business-rule, and unknown failures

## Security Rules

- store token securely
- never log bearer tokens
- never log full sensitive production payloads
- clear session on logout
- use HTTPS in staging and production
- keep secret-bearing integrations outside feature UI code

## Suggested Models, Repositories, and Use Cases

Suggested domain models:

- `AuthSession`
- `TeacherProfile`
- `TeacherAssignment`
- `TeacherTimetableResponse`
- `TeacherTimetableRow`
- `AttendanceSheetRow`
- `AttendanceSaveRequest`
- `MarksSheetRow`
- `MarksSaveRequest`
- `UserNotification`

Suggested repositories:

- `AuthRepository`
- `TeacherAcademicsRepository`
- `NotificationRepository`

Suggested use cases:

- `LoginUseCase`
- `RestoreSessionUseCase`
- `LogoutUseCase`
- `GetAssignmentsUseCase`
- `GetTeacherTimetableUseCase`
- `GetAttendanceSheetUseCase`
- `SaveAttendanceUseCase`
- `GetMarksSheetUseCase`
- `SaveMarksUseCase`
- `GetNotificationsUseCase`
- `MarkNotificationReadUseCase`

## Testing and Change Management

Required coverage:

- login success and failure
- token expiry handling
- assignments rendering
- empty timetable rendering
- attendance save success and validation failure
- marks save success and validation failure
- notification polling and mark-read flow
- storage migration tests
- DTO parsing tests for nullable and additive fields

Change-management rules:

- prefer additive changes
- deprecate before removing
- document module boundaries
- keep migration notes for storage and API changes
- do not run broad refactors without regression coverage

## Release Checklist

- environment configuration defined
- bearer token interceptor implemented
- teacher-only route guard implemented
- session restore implemented
- attendance submit and reload implemented
- marks submit and reload implemented
- notification polling implemented
- 401/403/422 handling verified
- storage versioning strategy defined
- DTO/domain boundaries enforced
- regression tests in place

## Final Rule

The Flutter team should build only against behavior that already exists in this repository. Any new mobile requirement that needs a new endpoint, response shape, permission change, or workflow aggregation must be added to the backend first and then documented in this master guide before app implementation begins.
