# Flutter Teacher App API Contract

## Purpose

This document gives the Flutter team a teacher-app-specific API contract based on the current SMS backend implementation.

Base API prefix:

- `/api/v1`

Authentication:

- Bearer token using Laravel Sanctum

## Global Request Rules

Protected requests must include:

```http
Authorization: Bearer <token>
Accept: application/json
Content-Type: application/json
```

## Global Error Expectations

- `200` request successful
- `401` unauthenticated or invalid token
- `403` authenticated but not allowed
- `422` validation or business-rule failure

The app should parse both:

- Laravel validation error bodies
- plain `message` responses

## Auth Endpoints

### `POST /login`

Request:

```json
{
  "email": "teacher@school.com",
  "password": "password"
}
```

Success response:

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

Notes:

- app should allow teacher flows only when effective role is `teacher`
- inactive account may return `403`

### `GET /user`

Purpose:

- validate stored session
- fetch authenticated profile

Response:

- wrapped as `{ "user": ... }`

### `POST /logout`

Success:

```json
{
  "message": "Logged out successfully"
}
```

### `POST /revoke-all-tokens`

Success:

```json
{
  "message": "All tokens revoked successfully"
}
```

### `POST /forgot-password`

Use:

- password reset initiation

### `POST /reset-password`

Use:

- complete password reset

## Teacher Assignment Endpoints

### `GET /teacher-academics/assignments`

Purpose:

- fetch teacher-scoped assignments

Response:

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

- `id` is the assignment primary key for attendance and marks flows
- `section_name` may be `All Sections`

## Teacher Timetable Endpoints

### `GET /teacher-academics/timetable`

Optional query:

- `academic_year_id`

Response:

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

- app should tolerate empty arrays
- `matrix` is useful for timetable grid rendering
- `rows` is useful for agenda/list rendering

## Attendance Endpoints

### `GET /teacher-academics/attendance-sheet`

Query:

- `assignment_id` required
- `date` required

Response:

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

- locked rows must be non-editable in UI
- only fetched enrollments should be submitted back

### `POST /teacher-academics/attendance`

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

Validation rules from backend:

- `attendances` min 1 max 200
- `status` must be one of allowed values
- invalid enrollment scope returns `422`
- locked rows are skipped by backend

Typical `422` body example:

```json
{
  "message": "Some enrollments do not belong to your assigned section.",
  "invalid_enrollment_ids": [999]
}
```

## Marks Endpoints

### `GET /teacher-academics/marks-sheet`

Query:

- `assignment_id` required
- `exam_configuration_id` required
- `marked_on` optional

Response:

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
- backend may reject mismatched mapped exam configuration with `422`

Typical `422` messages may include:

- `Selected exam does not belong to your assignment academic year.`
- `Selected exam is inactive. Contact super admin.`
- `Selected exam does not match assigned teacher exam configuration.`
- `Selected exam does not match mapped subject class assignment exam configuration.`

### `POST /teacher-academics/marks`

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

Validation rules from backend:

- `marks` min 1 max 200
- `marks_obtained` nullable numeric
- `max_marks` nullable numeric
- invalid enrollment scope returns `422`
- if `marks_obtained > max_marks`, backend skips that row

App-side safety rule:

- validate `marks_obtained <= max_marks` before submit

## Notification Endpoints

### `GET /notifications/unread-count`

Response:

```json
{
  "total": 3,
  "by_type": {
    "attendance": 2,
    "system": 1
  }
}
```

### `GET /notifications/recent`

Optional query:

- `limit` max 20

Response:

```json
{
  "data": [
    {
      "id": 1,
      "title": "Attendance saved",
      "message": "Attendance saved successfully.",
      "type": "attendance",
      "priority": "normal",
      "entity_type": null,
      "entity_id": null,
      "action_target": "/teacher/mark-attendance",
      "is_read": false,
      "read_at": null,
      "created_at": "2026-03-30T05:30:00Z",
      "meta": {}
    }
  ]
}
```

### `GET /notifications`

Query:

- `page`
- `per_page`
- `type`
- `status` where allowed values are `read`, `unread`, `all`

Response:

- Laravel paginated structure with transformed notification items in `data`

### `POST /notifications/{id}/read`

Success:

```json
{
  "message": "Notification marked as read.",
  "data": {
    "id": 1,
    "is_read": true
  }
}
```

### `POST /notifications/mark-all-read`

Success:

```json
{
  "message": "All notifications marked as read.",
  "updated_count": 3
}
```

## Client Parsing Rules

The Flutter app should:

- ignore unknown response keys safely
- tolerate nulls where backend may return null
- avoid assuming non-empty arrays
- map remote values into domain-safe enums or constants

## Session Handling Rules

- on `401`, clear session and return to login
- on `403`, show unauthorized or teacher-access-required state
- on `422`, surface validation or business-rule feedback without logging user out

## Polling Guidance

For notifications:

- poll only while app is foregrounded
- recommended interval: 60 to 120 seconds
- stop polling when session is invalid or app is backgrounded

## Contract Stability Rule

This contract is based on the current repository implementation. If backend response shape or endpoint behavior changes, this document should be updated together with the server change before the Flutter team consumes the new behavior.
