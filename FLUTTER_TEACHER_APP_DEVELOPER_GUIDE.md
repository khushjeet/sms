# Flutter Teacher App Developer Guide

## Purpose

This document defines the Flutter teacher application against the current SMS backend implementation in this repository.

The goal is to help the Flutter team build a teacher-focused app with iOS-style UI behavior that is fully aligned with:

- Laravel 12 backend APIs
- Laravel Sanctum Bearer-token authentication
- Existing teacher role and module permissions
- Current teacher academic workflows already implemented in `routes/api.php`

This is not a greenfield product spec. It is an implementation guide for building a Flutter app on top of the system that already exists.

## Durability Goal

This app must be designed as a long-life product, not as a short-term feature bundle.

Engineering target:

- scalable enough to support 30 to 70+ feature areas over time
- maintainable over 20 to 30 years
- safe to evolve without breaking existing teacher workflows
- resilient to backend growth, UI redesigns, and team changes

That means the app architecture must optimize for:

- separation of concerns
- backward-compatible evolution
- low coupling between features
- replaceable infrastructure layers
- testability
- stable contracts
- safe rollout patterns

## System Alignment

The Flutter app must follow the current backend behavior exactly.

Backend facts from this codebase:

- API base prefix is `/api/v1`
- Authentication is token-based using Laravel Sanctum
- Teacher access is role-gated
- Teacher academic workflows are already exposed under `/teacher-academics/*`
- Notifications are exposed under `/notifications/*`
- Timetable data for teachers is exposed under `/teacher-academics/timetable`
- Password reset APIs already exist

Primary backend references:

- `routes/api.php`
- `app/Http/Controllers/Api/AuthController.php`
- `app/Http/Controllers/Api/TeacherAcademicController.php`
- `app/Http/Controllers/Api/TimetableController.php`
- `app/Http/Controllers/Api/NotificationController.php`
- `API_FRONTEND_GUIDE.md`

## Target Users

The Flutter app in this phase is for users with teacher access.

Supported user type:

- `teacher`

Important note:

- The backend contains some timetable and assignment paths where `staff` can appear in admin-side assignment flows, but the teacher mobile app should be designed only for authenticated users whose effective role is `teacher`.

## Supported App Scope

The Android teacher app should support these workflows in phase 1:

1. Login
2. Restore session from saved token
3. Logout
4. Password reset request
5. Fetch authenticated profile
6. View teacher subject assignments
7. View teacher timetable
8. Load attendance sheet for an assigned class/section
9. Save attendance for an assigned class/section
10. Load marks sheet for an assigned class/section and exam configuration
11. Save marks for an assigned class/section and exam configuration
12. View notifications
13. Mark notification as read
14. Mark all notifications as read

Out of scope for this teacher app unless backend support is added later:

- Admin dashboards
- Student management CRUD
- Finance and transport flows
- Teacher document upload and employee management
- Real-time socket notifications
- Offline-first write sync with conflict merging

## Recommended Flutter Stack

Recommended implementation:

- Language: Dart
- Framework: Flutter
- Architecture: Clean Architecture with feature-first modules
- State management: Riverpod or Bloc
- Networking: Dio
- Serialization: `json_serializable` or `freezed`
- Local storage: `flutter_secure_storage` for token, Hive or Drift for cache
- Routing: `go_router`

Suggested package layout:

```text
lib/
|- app/
|- core/
|  |- network/
|  |- storage/
|  |- theme/
|  |- common/
|- data/
|  |- remote/
|  |- local/
|  |- repositories/
|- domain/
|  |- models/
|  |- repositories/
|  |- usecases/
|- features/
|  |- auth/
|  |- home/
|  |- assignments/
|  |- timetable/
|  |- attendance/
|  |- marks/
|  |- notifications/
```

Scalability rules for this structure:

- every feature owns its UI, controller/state, domain use cases, repository contracts, and API models
- shared code belongs in `core/` only when it is truly cross-feature
- no feature should directly depend on another feature's internal files
- feature-to-feature reuse should happen through domain contracts or shared application services
- keep generated code isolated from handwritten business code

## UI Direction

The app is built in Flutter, but the visual language should feel iOS-first.

Recommended UI approach:

- use `Cupertino` patterns where practical
- use iOS-style page transitions
- use bottom sheets, segmented controls, and large-title navigation patterns where they fit
- keep forms simple, clean, and touch-friendly
- preserve platform-safe behavior on Android even if the style direction is iOS-inspired

Practical guidance:

- `CupertinoPageScaffold` or Material scaffolds styled to feel iOS-like
- soft surfaces, clean spacing, rounded cards, restrained color use
- use native-feeling date pickers for attendance date and marks date selection
- use segmented filters for notifications and timetable views when useful

To keep the UI durable for many years:

- use design tokens for colors, spacing, radius, typography, elevation, and motion
- avoid hardcoding styles inside widgets
- define reusable app components for lists, cards, form rows, states, and action bars
- keep business logic outside widgets
- prefer composition over giant reusable mega-widgets

## Environment Configuration

The app should keep the API base URL configurable per environment.

Example environments:

- Local: `http://10.0.2.2:8000/api/v1`
- Staging: project-specific staging URL
- Production: `https://api.ipsyogapatti.com/api/v1`

Do not hardcode production values in feature code. Keep them in build config or environment configuration.

For long-term durability:

- keep environment config externalized
- version remote configuration shape carefully
- support adding new endpoints without touching unrelated features
- isolate base URL, headers, timeouts, and logging setup in one network module

## Authentication Model

### Login

Endpoint:

- `POST /login`

Request:

```json
{
  "email": "teacher@school.com",
  "password": "password"
}
```

Success response shape:

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
    "avatar_url": "https://...",
    "full_name": "Anita Kumari",
    "status": "active"
  }
}
```

Flutter app rules:

- Save `token`, `expires_at`, and `user`
- Send `Authorization: Bearer <token>` on all protected requests
- Reject app entry into teacher flows if `user.role` is not `teacher`
- Treat `403` on login as inactive-account or forbidden state

### Current User

Endpoint:

- `GET /user`

Use this on app start to validate a stored token and refresh the authenticated user profile.

Session durability rules:

- app startup must tolerate expired or revoked tokens cleanly
- auth state should be represented by a single source of truth
- feature screens must react to session invalidation without crashing
- secure logout should clear volatile in-memory state as well as persisted auth state

### Logout

Endpoint:

- `POST /logout`

On success:

- clear token
- clear user session
- clear cached teacher-private data if required by product policy

### Revoke All Tokens

Endpoint:

- `POST /revoke-all-tokens`

This is optional for the first Android release, but useful for future account-security settings.

### Password Reset

Endpoints:

- `POST /forgot-password`
- `POST /reset-password`

The first Android release should at least support the reset-link request flow.

## Authorization Rules

The mobile app must not assume that authentication alone is enough.

Backend authorization uses:

- `auth:sanctum`
- role checks
- permission checks
- module checks

Teacher-related backend expectations:

- Teacher must be authenticated
- Teacher must have role `teacher`
- Teacher can only access assignments allotted to that teacher
- Teacher can only submit attendance/marks for enrollments inside the assigned class/section scope
- Teacher can only submit marks against valid active exam configurations

Expected error meanings:

- `401` unauthenticated or invalid token
- `403` authenticated but not allowed
- `422` validation or business-rule failure

Long-term compatibility rule:

- authorization logic must be backend-driven; the app may optimize UX based on role or known capabilities, but must never become the final authority for access control

## Core Teacher API Surface

The Android teacher app should use these endpoints.

### Auth

- `POST /login`
- `GET /user`
- `POST /logout`
- `POST /revoke-all-tokens`
- `POST /forgot-password`
- `POST /reset-password`

### Teacher academics

- `GET /teacher-academics/assignments`
- `GET /teacher-academics/timetable`
- `GET /teacher-academics/attendance-sheet`
- `POST /teacher-academics/attendance`
- `GET /teacher-academics/marks-sheet`
- `POST /teacher-academics/marks`

### Notifications

- `GET /notifications`
- `GET /notifications/unread-count`
- `GET /notifications/recent`
- `POST /notifications/{id}/read`
- `POST /notifications/mark-all-read`

## Scalability and Compatibility Principles

These are mandatory platform rules for keeping the app stable over decades.

### 1. Feature Isolation

- each feature must be independently developable, testable, and releasable
- a new feature must not require edits across many old features unless intentionally shared
- avoid circular imports and shared mutable state

### 2. Stable Domain Contracts

- define domain models separate from raw API DTOs
- map remote responses into domain models at the data layer boundary
- never let backend response details leak through the whole UI tree
- if the backend evolves, update DTO mappings before touching feature UI

### 3. Backward-Compatible Growth

- treat all API fields as potentially additive
- ignore unknown JSON fields safely
- avoid assumptions that arrays are non-empty
- allow nullable fields where backend already permits null
- do not break old screens when new server fields appear

### 4. Replaceable Infrastructure

- networking, storage, logging, analytics, notifications, and config must be swappable modules
- feature logic must not depend on a specific HTTP client or cache engine directly
- infrastructure should sit behind abstractions owned by the app

### 5. Durable Navigation

- keep route names stable
- centralize navigation definitions
- use typed route arguments where possible
- avoid deep-link schemes tightly coupled to one UI layout

### 6. Durable State Management

- one feature should own one state container or controller boundary
- do not create hidden global mutable state
- state objects should be serializable where practical
- loading, success, empty, error, and stale-cache states must all be first-class

### 7. Testability by Default

- every repository and use case should be mockable
- business rules must be testable without widgets
- widget trees should stay thin enough to test deterministically
- regressions should be caught with contract, unit, widget, and integration tests

### 8. Safe Evolution

- use feature flags for risky rollouts
- prefer additive changes over destructive refactors
- deprecate old code paths gradually
- version critical local storage schemas carefully

### 9. Observability

- log failures with sanitized metadata
- track endpoint, feature, response class, and retry context
- keep crash and error reporting decoupled from feature code

### 10. Offline and Cache Discipline

- define cache ownership clearly per feature
- cache only what improves user experience materially
- never allow stale cached data to silently overwrite fresh backend truth
- write paths should remain online-first unless a dedicated sync engine is introduced

## Screen Map

Recommended Android screen structure:

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

Future feature growth expectation:

- additional teacher features should be added as new feature modules rather than expanding a single home module into a monolith
- when the app later includes principal, staff, parent, or student experiences, those should be new bounded feature groups, not mixed into teacher feature internals

### Home Dashboard

Recommended sections:

- teacher profile summary
- quick actions
- today timetable preview
- assignment count
- unread notification count

This dashboard should be assembled from existing endpoints rather than requiring a new backend dashboard endpoint.

## Feature Details

### 1. My Assignments

Endpoint:

- `GET /teacher-academics/assignments`

Purpose:

- show what subjects/classes/sections the teacher is allowed to work on
- provide `assignment_id` for attendance and marks flows

Key response fields:

- `id`
- `subject_id`
- `section_id`
- `class_id`
- `academic_year_id`
- `subject_name`
- `subject_code`
- `section_name`
- `class_name`
- `academic_year_name`
- `mapped_max_marks`
- `mapped_pass_marks`
- `mapped_exam_configuration_id`
- `mapped_exam_configuration_name`

Flutter UI guidance:

- group by academic year or class
- show subject + class + section together
- treat `id` as the assignment primary key for downstream actions
- never derive permissions from UI grouping; assignment ownership still comes from backend validation

### 2. My Timetable

Endpoint:

- `GET /teacher-academics/timetable`

Optional query:

- `academic_year_id`

Purpose:

- display weekly teacher timetable
- allow academic-year switching where multiple years are returned

Important response sections:

- `meta`
- `academic_year_options`
- `days`
- `slots`
- `rows`
- `matrix`

Flutter UI guidance:

- Use `matrix` for grid rendering
- Use `rows` for list rendering or daily agenda
- Support empty timetable gracefully
- keep timetable rendering generic so future filters, exports, or substitute-teacher features can be added without redesigning data flow

### 3. Attendance Sheet

Endpoint:

- `GET /teacher-academics/attendance-sheet`

Query parameters:

- `assignment_id` required
- `date` required

Purpose:

- fetch students for the assigned class/section
- load current attendance state for a selected date

Row fields include:

- `enrollment_id`
- `student_id`
- `roll_number`
- `student_name`
- `status`
- `remarks`
- `is_locked`

Behavior rules:

- `status` may be `not_marked`, `present`, `absent`, `leave`, or `half_day`
- if `is_locked` is `true`, disable editing for that row
- the teacher should not be allowed to submit attendance for students outside the fetched list

### 4. Save Attendance

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
    },
    {
      "enrollment_id": 502,
      "status": "absent",
      "remarks": "Sick leave informed"
    }
  ]
}
```

Allowed status values:

- `present`
- `absent`
- `leave`
- `half_day`

Success response:

```json
{
  "message": "Attendance saved successfully."
}
```

Important backend rules:

- max 200 attendance rows per request
- invalid enrollment IDs return `422`
- locked rows are skipped by backend
- teacher notifications are triggered for admins after successful save

Flutter submission guidance:

- submit only rows visible in the fetched sheet
- block duplicate taps while request is in progress
- after save, reload the sheet for the same date to reflect final backend state
- keep attendance editor row models isolated from network DTOs so future attendance status expansion stays contained

### 5. Marks Sheet

Endpoint:

- `GET /teacher-academics/marks-sheet`

Query parameters:

- `assignment_id` required
- `exam_configuration_id` required
- `marked_on` optional

Purpose:

- fetch student rows and previously saved marks for a given exam configuration and date

Response shape:

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

Backend rules:

- selected exam configuration must belong to the same academic year as the assignment
- selected exam configuration must be active
- selected exam configuration must match mapped or assigned exam configuration rules

Flutter guidance:

- prefill `marked_on` with today
- use assignment payload fields `mapped_exam_configuration_id` and `mapped_exam_configuration_name` as the default exam choice where available
- build marks entry widgets to support future grading modes without rewriting screen navigation

### 6. Save Marks

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

Success response:

```json
{
  "message": "Marks saved successfully."
}
```

Important backend rules:

- max 200 marks rows per request
- `marks_obtained` may be null
- `max_marks` defaults from assignment mapping or `100`
- if `marks_obtained > max_marks`, that row is skipped by backend
- invalid enrollment IDs return `422`

Flutter validation guidance:

- validate `marks_obtained <= max_marks` on device before submit
- show numeric keyboards only
- allow remarks as optional text
- reload the marks sheet after successful save
- keep marks validation rules centralized so future exam rule changes affect one place

### 7. Notifications

Endpoints:

- `GET /notifications/unread-count`
- `GET /notifications/recent`
- `GET /notifications`
- `POST /notifications/{id}/read`
- `POST /notifications/mark-all-read`

Recommended use:

- home badge: `GET /notifications/unread-count`
- home preview: `GET /notifications/recent?limit=5`
- full list: `GET /notifications?page=1&per_page=20&status=all`

Durability guidance:

- notification rendering must be schema-tolerant
- unknown notification `type` values should still render safely
- `action_target` handling should fail gracefully when the app has no matching screen

Notification fields:

- `id`
- `title`
- `message`
- `type`
- `priority`
- `entity_type`
- `entity_id`
- `action_target`
- `is_read`
- `read_at`
- `created_at`
- `meta`

Flutter guidance:

- poll periodically while app is foregrounded
- use conservative polling, for example every 60 to 120 seconds
- support local deep-link routing based on `action_target` where meaningful

## Local Data Strategy

Use local persistence for:

- auth token
- user session
- last fetched assignments
- timetable cache
- recent notification cache

Recommended approach:

- `flutter_secure_storage` for token, expiry, user summary
- Hive or Drift for cached API entities

Do not treat local cache as source of truth for attendance or marks writes.

Durable storage rules:

- version every persisted schema
- write migrations for local database changes
- do not persist raw backend blobs when stable local models are enough
- keep per-feature cache boxes/tables separate where practical
- define retention rules for stale cache cleanup

## Offline Behavior

Recommended phase 1 offline behavior:

- allow read-only access to last cached assignments, timetable, and notifications
- disable attendance and marks submission when network is unavailable
- show clear sync status messaging

Avoid background queued write submission in the first release because the backend currently behaves as online request-response APIs and does not expose conflict-resolution support for delayed mobile sync.

If a future offline sync engine is introduced:

- make it a dedicated platform service
- add operation journals, retry policies, conflict policies, and reconciliation logs
- do not hide sync logic inside individual feature widgets

## Error Handling Contract

The Flutter app should standardize API error handling.

### `401`

Meaning:

- token missing
- token expired
- token invalid

App behavior:

- clear session
- redirect to login

### `403`

Meaning:

- teacher access required
- role/module/permission denied
- assignment not allotted to current teacher

App behavior:

- show permission-safe error message
- stay logged in unless token is invalid

### `422`

Meaning:

- request validation failed
- exam configuration mismatch
- academic-year mismatch
- invalid enrollment IDs

App behavior:

- parse field errors where available
- otherwise show backend message

Durable error-model rule:

- define one shared app error model with transport errors, auth errors, validation errors, business-rule errors, and unknown errors
- do not let each feature invent its own incompatible error structure

## Security Requirements

The Flutter app must follow these rules:

- store tokens in encrypted local storage where possible
- never log bearer tokens
- never log full request payloads containing teacher-submitted data in production
- clear session on logout
- avoid screenshotting sensitive screens if product policy requires it
- use HTTPS in staging and production

Long-life security expectations:

- dependencies must be reviewable and replaceable
- secret-bearing integrations must stay outside feature UI modules
- app logging policy must remain token-safe across future features
- support certificate or transport hardening later without rewriting feature code

## API Client Rules

Every protected request must send:

```http
Authorization: Bearer <token>
Accept: application/json
Content-Type: application/json
```

Recommended Dio interceptor responsibilities:

- inject bearer token
- detect `401`
- map backend error bodies to a shared API exception model

Durability rules for the data layer:

- one API client configuration
- one error parsing strategy
- one retry policy strategy
- DTO-to-domain mapping at repository boundary
- no feature should call Dio directly from presentation code

## Suggested Domain Models

Suggested Flutter domain models:

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

Add model discipline:

- separate remote DTOs, local entities, and domain models
- use immutable models
- prefer explicit value objects for IDs, dates, and statuses where complexity grows

## Suggested Repositories

- `AuthRepository`
- `TeacherAcademicsRepository`
- `NotificationRepository`

Repository rules:

- repositories expose app-facing contracts, not raw HTTP responses
- repositories may combine cache and remote logic
- repositories should remain small and feature-bounded

## Suggested Use Cases

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

Use case rules:

- one use case per business action
- no widget should implement business rules directly
- orchestration that spans multiple repositories should live in use cases or application services

## Recommended Navigation Flow

1. Splash checks saved token
2. If token exists, call `GET /user`
3. If valid and role is `teacher`, open home
4. Load assignments, unread notification count, and timetable preview
5. User enters attendance or marks flow through assignment selection

For future expansion:

- keep shell navigation extensible for many modules
- do not hardwire app startup to a teacher-only home forever; support role-based shells if the product grows

## Testing Requirements

The Flutter team should cover:

- successful login
- inactive-account login failure
- unauthorized role login
- token expiration handling
- assignment list rendering
- empty timetable rendering
- attendance save success
- attendance save validation failure
- marks save success
- marks validation failure
- notification polling and mark-read flow

Recommended test layers:

- unit tests for repositories and use cases
- widget tests for major teacher flows
- integration tests for login, attendance, and marks save flows
- API contract tests using mocked backend payloads

Long-term regression policy:

- critical teacher workflows must have automated regression coverage before major refactors
- local database migrations must be tested
- DTO parsing must be tested for nullable and additive fields
- golden or snapshot tests may be used sparingly for stable design-system components

## Change Management Rules

To keep the app stable for 20 to 30 years, future changes should follow these rules:

- prefer additive schema changes
- deprecate before removing
- keep migration notes for storage and API model changes
- document every new feature module with ownership boundaries
- introduce cross-feature shared abstractions only after repeated proven need
- do not turn `core/` into a dumping ground

## Recommended Growth Path

When the app grows from 7 features to 30 to 70+ features, expand in layers:

1. Add new feature modules with their own contracts and tests
2. Promote repeated patterns into shared application services carefully
3. Introduce feature flags for partial rollout
4. Add observability and performance monitoring before complexity spikes
5. Review module boundaries regularly to prevent monolith drift

If the product later becomes a multi-role school super app, the correct direction is:

- shared platform layer
- separate bounded feature groups per role
- stable backend contracts
- progressive migration, not wholesale rewrites

## Release Checklist

- base URLs configured per environment
- bearer token interceptor implemented
- teacher-only route guard implemented
- session restore implemented
- attendance submit and reload flow implemented
- marks submit and reload flow implemented
- notification polling implemented
- 401/403/422 handling verified
- production logging sanitized
- local storage versioning strategy defined
- DTO/domain mapping boundaries respected
- core modules reviewed for unwanted coupling
- critical workflows covered by regression tests

## Future Enhancements

Possible future teacher-app expansions after backend confirmation:

- self-attendance support via `/dashboard/self-attendance/*`
- teacher dashboard aggregation endpoint
- result viewing for teacher role where product wants it
- downloadable timetable PDF support if mobile file download is needed
- push notifications in place of polling

## Final Implementation Rule

The Flutter team should build only against behavior that is already available in this repository. If a new mobile screen requires a new aggregation endpoint, response shape, or permission relaxation, that must be added to the backend first and then documented here before app implementation begins.
