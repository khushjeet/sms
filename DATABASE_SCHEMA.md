# Database Schema Documentation

##  Entity Relationship Overview

```
+---------------------------------------------------------------------+
|                    SCHOOL MANAGEMENT SYSTEM                          |
|                         Database Schema                               |
+---------------------------------------------------------------------+

Core Entities Hierarchy:

users
  |
  +-- students ------+
  |                   |
  +-- parents        |
  |                   |
  +-- staff          |
                      |
    academic_years    |
         |            |
         +-- sections |
         |     |      |
         |     +------+--- enrollments (CENTRAL)
         |            |         |
         +----------------------+--- attendances
         |                      |
         +----------------------+--- results
         |                      |
         +----------------------+--- fee_assignments
                                |
                                +--- payments
```

##  Core Relationships

### 1. User Management

```
users (Base authentication table)
+-- id (PK)
+-- email (unique)
+-- password
+-- role (enum: super_admin, school_admin, accountant, teacher, parent, student)
+-- first_name
+-- last_name
+-- status

Relationships:
+-- hasOne: student, parent, staff
```

### 2. Student Lifecycle

```
students (Permanent Identity)
+-- id (PK)
+-- user_id (FK -> users)
+-- admission_number (unique)
+-- admission_date
+-- date_of_birth
+-- gender
+-- status (active, alumni, transferred, dropped)
+-- [address, medical info, etc.]

Relationships:
+-- belongsTo: user
+-- belongsToMany: parents (via student_parent pivot)
+-- hasMany: enrollments
```

### 3. Academic Structure

```
academic_years
+-- id (PK)
+-- name (e.g., "2024-2025")
+-- start_date
+-- end_date
+-- is_current (boolean)
+-- status (active, closed, archived)

classes
+-- id (PK)
+-- name (e.g., "Class 1")
+-- numeric_order (for sorting)
+-- status

sections
+-- id (PK)
+-- class_id (FK -> classes)
+-- academic_year_id (FK -> academic_years)
+-- name (e.g., "A", "B")
+-- capacity
+-- class_teacher_id (FK -> users)
+-- room_number

UNIQUE KEY: (class_id, academic_year_id, name)
```

### 4. Enrollment (Year-Based Placement)

```
enrollments * CENTRAL TABLE *
+-- id (PK)
+-- student_id (FK -> students)
+-- academic_year_id (FK -> academic_years)
+-- section_id (FK -> sections)
+-- roll_number
+-- enrollment_date
+-- status (active, promoted, repeated, transferred, dropped)
+-- is_locked (boolean - prevents modifications)
+-- promoted_from_enrollment_id (FK -> enrollments, nullable)
+-- remarks

UNIQUE KEY: (student_id, academic_year_id)

Business Rules:
1. One enrollment per student per academic year
2. Locked enrollments are read-only
3. Historical data is immutable
4. Status changes trigger specific workflows
```

### 5. Attendance System

```
attendances
+-- id (PK)
+-- enrollment_id (FK -> enrollments)
+-- date
+-- status (present, absent, leave, half_day)
+-- remarks
+-- marked_by (FK -> users)
+-- marked_at
+-- is_locked (boolean)

UNIQUE KEY: (enrollment_id, date)

Business Rules:
1. One record per enrollment per date
2. Cannot mark attendance for locked enrollments
3. Attendance can be locked after cutoff time
4. Only assigned teachers can mark
```

### 6. Examination & Results

```
exams
+-- id (PK)
+-- academic_year_id (FK -> academic_years)
+-- name (e.g., "Mid Term 2024")
+-- type (unit_test, mid_term, final, practical)
+-- start_date
+-- end_date
+-- status (scheduled, ongoing, completed, cancelled)

exam_schedules
+-- id (PK)
+-- exam_id (FK -> exams)
+-- class_id (FK -> classes)
+-- subject_id (FK -> subjects)
+-- exam_date
+-- start_time
+-- end_time
+-- max_marks

results
+-- id (PK)
+-- enrollment_id (FK -> enrollments)
+-- exam_id (FK -> exams)
+-- subject_id (FK -> subjects)
+-- marks_obtained
+-- max_marks
+-- grade (A+, A, B+, B, C, D, F)
+-- entered_by (FK -> users)
+-- is_locked

UNIQUE KEY: (enrollment_id, exam_id, subject_id)
```

### 7. Fee Management

```
fee_structures (Base fees per class)
+-- id (PK)
+-- class_id (FK -> classes)
+-- academic_year_id (FK -> academic_years)
+-- fee_type (Tuition, Admission, Annual, etc.)
+-- amount
+-- frequency (one_time, monthly, quarterly, annually)
+-- is_mandatory

optional_services
+-- id (PK)
+-- academic_year_id (FK -> academic_years)
+-- name (Transport, Hostel, Meals, Activities)
+-- amount
+-- frequency
+-- status

fee_assignments (Per enrollment)
+-- id (PK)
+-- enrollment_id (FK -> enrollments, unique)
+-- base_fee (sum of mandatory fees)
+-- optional_services_fee
+-- discount (scholarship amount)
+-- total_fee (calculated)
+-- discount_reason

payments (Immutable records)
+-- id (PK)
+-- enrollment_id (FK -> enrollments)
+-- receipt_number (unique)
+-- amount
+-- payment_date
+-- payment_method (cash, cheque, online, card, upi)
+-- transaction_id
+-- received_by (FK -> users)
+-- is_refunded

financial_holds
+-- id (PK)
+-- student_id (FK -> students)
+-- outstanding_amount
+-- is_active
+-- reason
```

### 8. Subjects & Teacher Assignments

```
subjects (Versioned + durable)
|-- id (PK)
|-- subject_code (unique, immutable)
|-- name
|-- short_name
|-- category (core, elective, lab, activity)
|-- credits
|-- grading_scheme_id (nullable FK)
|-- effective_from (date)
|-- effective_to (date, nullable)
|-- is_active (boolean)
|-- board_code (nullable)
|-- lms_code (nullable)
|-- erp_code (nullable)
`-- archived_at (nullable)

Rules:
- Do not hard delete subjects that are referenced by exams/results/timetable.
- Keep old rows for historical transcript/report reconstruction.
- Breaking definition changes create a new subject version row.

class_subjects (Subject assignment to class/year)
|-- id (PK)
|-- class_id (FK -> classes)
|-- subject_id (FK -> subjects)
|-- academic_year_id (FK -> academic_years)
|-- max_marks
|-- pass_marks
`-- is_mandatory

UNIQUE KEY: (class_id, subject_id, academic_year_id)

teacher_subject_assignments (Effective-dated ledger)
|-- id (PK)
|-- teacher_id (FK -> users)
|-- section_id (FK -> sections)
|-- subject_id (FK -> subjects)
|-- academic_year_id (FK -> academic_years)
|-- assigned_from (date)
|-- assigned_to (date, nullable)
|-- status (active, inactive)
|-- primary_flag (boolean)
|-- workload_percent (nullable)
|-- periods_per_week (nullable)
|-- substitute_teacher_id (nullable FK -> users)
|-- external_assignment_key (nullable, immutable when set)
`-- version (integer, default 1)

Recommended uniqueness and overlap controls:
- UNIQUE KEY: (teacher_id, section_id, subject_id, academic_year_id, assigned_from)
- Prevent overlapping active windows for same section + subject + academic_year.
- Reassignment should close old record (`assigned_to`) and insert new row.
```

### 8.1 Long-Term Non-Breaking Migration Policy

1. Additive-first schema evolution: only add nullable columns and backfill.
2. Never repurpose existing columns for new meaning.
3. Avoid cascade deletes on subject/assignment master tables.
4. Preserve immutable IDs for external integrations and exports.
5. Store assignment snapshot references in attendance/exam/timetable publishing flows.

### 9. Staff & HR

```
staff
+-- id (PK)
+-- user_id (FK -> users)
+-- employee_id (unique)
+-- joining_date
+-- employee_type (teaching, non_teaching)
+-- designation
+-- department
+-- qualification
+-- salary
+-- status (active, on_leave, resigned, terminated)

staff_attendances
+-- id (PK)
+-- staff_id (FK -> staff)
+-- date
+-- status (present, absent, leave, half_day)
+-- check_in
+-- check_out
+-- remarks

UNIQUE KEY: (staff_id, date)

staff_leaves
+-- id (PK)
+-- staff_id (FK -> staff)
+-- leave_type_id (FK -> leave_types)
+-- start_date
+-- end_date
+-- total_days
+-- reason
+-- status (pending, approved, rejected)
+-- approved_by (FK -> users)
```

### 10. Timetable Management

```
time_slots
+-- id (PK)
+-- name (Period 1, Period 2, Break)
+-- start_time
+-- end_time
+-- is_break
+-- slot_order

timetables
+-- id (PK)
+-- section_id (FK -> sections)
+-- academic_year_id (FK -> academic_years)
+-- day_of_week (monday-saturday)
+-- time_slot_id (FK -> time_slots)
+-- subject_id (FK -> subjects)
+-- teacher_id (FK -> users)
+-- room_number

UNIQUE KEY: (section_id, day_of_week, time_slot_id, academic_year_id)

Prevents:
- Double booking of sections
- Teacher conflicts
```

### 11. Library Management

```
books
+-- id (PK)
+-- title
+-- author
+-- isbn (unique)
+-- publisher
+-- category
+-- total_copies
+-- available_copies
+-- status

book_issues
+-- id (PK)
+-- book_id (FK -> books)
+-- student_id (FK -> students)
+-- issue_date
+-- due_date
+-- return_date (nullable)
+-- fine_amount
+-- fine_paid
+-- status (issued, returned, overdue)
```

### 12. Transport System

```
transport_routes
+-- id (PK)
+-- route_name
+-- route_number (unique)
+-- description
+-- fee_amount
+-- status

transport_stops
+-- id (PK)
+-- route_id (FK -> transport_routes)
+-- stop_name
+-- pickup_time
+-- drop_time
+-- stop_order

student_transport
+-- id (PK)
+-- student_id (FK -> students)
+-- route_id (FK -> transport_routes)
+-- stop_id (FK -> transport_stops)
+-- academic_year_id (FK -> academic_years)
+-- status

UNIQUE KEY: (student_id, academic_year_id)
```

### 13. Notifications

```
notifications
+-- id (PK)
+-- title
+-- message
+-- type (academic, financial, administrative, emergency)
+-- target_audience (all, students, parents, teachers, staff)
+-- target_classes (JSON - specific classes if needed)
+-- sent_by (FK -> users)
+-- sent_at
+-- status (draft, sent)

notification_reads (Tracking who read)
+-- id (PK)
+-- notification_id (FK -> notifications)
+-- user_id (FK -> users)
+-- read_at

UNIQUE KEY: (notification_id, user_id)
```

### 14. Audit System

```
audit_logs (Immutable)
+-- id (PK)
+-- user_id (FK -> users, nullable)
+-- action (create, update, delete, login, etc.)
+-- model_type (Student, Enrollment, Payment, etc.)
+-- model_id
+-- old_values (JSON)
+-- new_values (JSON)
+-- ip_address
+-- user_agent
+-- reason (for admin overrides)
+-- created_at

Indexes:
+-- (user_id, action)
+-- (model_type, model_id)
+-- created_at
```

##  Foreign Key Constraints

### ON DELETE Behaviors

```
CASCADE (Child records deleted):
- students -> user
- enrollments -> student
- attendances -> enrollment
- results -> enrollment
- payments -> enrollment

SET NULL (FK set to null):
- sections -> class_teacher_id
- enrollments -> promoted_from_enrollment_id
- audit_logs -> user_id

RESTRICT (Prevent deletion):
- classes (if sections exist)
- academic_years (if enrollments exist)
```

##  Indexes for Performance

### Critical Indexes

```sql
-- User lookups
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_role ON users(role);

-- Student searches
CREATE INDEX idx_students_admission_number ON students(admission_number);
CREATE INDEX idx_students_status ON students(status);

-- Enrollment queries
CREATE INDEX idx_enrollments_student_year ON enrollments(student_id, academic_year_id);
CREATE INDEX idx_enrollments_section ON enrollments(section_id);
CREATE INDEX idx_enrollments_status ON enrollments(status);

-- Attendance lookups
CREATE INDEX idx_attendances_enrollment_date ON attendances(enrollment_id, date);
CREATE INDEX idx_attendances_date ON attendances(date);

-- Result queries
CREATE INDEX idx_results_enrollment_exam ON results(enrollment_id, exam_id);
CREATE INDEX idx_results_locked ON results(is_locked);

-- Payment tracking
CREATE INDEX idx_payments_receipt ON payments(receipt_number);
CREATE INDEX idx_payments_enrollment ON payments(enrollment_id);
CREATE INDEX idx_payments_date ON payments(payment_date);

-- Staff lookups
CREATE INDEX idx_staff_employee_id ON staff(employee_id);
CREATE INDEX idx_staff_type_status ON staff(employee_type, status);

-- Audit trail
CREATE INDEX idx_audit_user_action ON audit_logs(user_id, action);
CREATE INDEX idx_audit_model ON audit_logs(model_type, model_id);
CREATE INDEX idx_audit_created ON audit_logs(created_at);
```

##  Storage Estimates (1000 students)

```
Table                    | Records  | Avg Size | Total
-------------------------|----------|----------|--------
users                    | 3,000    | 1 KB     | 3 MB
students                 | 1,000    | 2 KB     | 2 MB
enrollments              | 5,000    | 1 KB     | 5 MB
attendances              | 200,000  | 200 B    | 40 MB
results                  | 50,000   | 300 B    | 15 MB
payments                 | 5,000    | 500 B    | 2.5 MB
audit_logs              | 500,000  | 1 KB     | 500 MB
-------------------------|----------|----------|--------
Total (approx)          |          |          | ~570 MB

Note: Excludes indexes (~30% overhead)
Projected 5-year storage: ~3 GB
```

##  Data Lifecycle

### Active Data (Current Academic Year)
- `status = 'active'`
- `is_locked = false`
- `is_current = true` (academic_years)

### Historical Data (Past Years)
- `status != 'active'`
- `is_locked = true`
- `is_current = false`

### Archival Strategy
1. **After 5 years**: Move to archive database
2. **After 10 years**: Cold storage (if required)
3. **Never delete**: Audit logs and financial records

##  Sample Queries

### Get Student's Current Class
```sql
SELECT s.admission_number, u.first_name, u.last_name, 
       c.name as class_name, sec.name as section_name
FROM students s
JOIN users u ON s.user_id = u.id
JOIN enrollments e ON s.id = e.student_id
JOIN sections sec ON e.section_id = sec.id
JOIN classes c ON sec.class_id = c.id
JOIN academic_years ay ON e.academic_year_id = ay.id
WHERE e.status = 'active' AND ay.is_current = true;
```

### Student Attendance Percentage
```sql
SELECT e.id, s.admission_number,
       COUNT(*) as total_days,
       SUM(CASE WHEN a.status IN ('present', 'half_day') THEN 1 ELSE 0 END) as present_days,
       ROUND(SUM(CASE WHEN a.status IN ('present', 'half_day') THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as percentage
FROM enrollments e
JOIN students s ON e.student_id = s.id
JOIN attendances a ON e.id = a.enrollment_id
WHERE e.id = ?
GROUP BY e.id, s.admission_number;
```

### Pending Fee Report
```sql
SELECT s.admission_number, u.first_name, u.last_name,
       fa.total_fee, COALESCE(SUM(p.amount), 0) as paid,
       (fa.total_fee - COALESCE(SUM(p.amount), 0)) as pending
FROM enrollments e
JOIN students s ON e.student_id = s.id
JOIN users u ON s.user_id = u.id
JOIN fee_assignments fa ON e.id = fa.enrollment_id
LEFT JOIN payments p ON e.id = p.enrollment_id
WHERE e.status = 'active'
GROUP BY s.id, fa.total_fee
HAVING pending > 0;
```

##  Data Integrity Rules

1. **Referential Integrity**: All FKs have proper constraints
2. **Unique Constraints**: Prevent duplicate records
3. **Check Constraints**: Validate enum values
4. **Soft Deletes**: Most tables use soft deletes
5. **Timestamps**: All tables track created_at/updated_at
6. **Audit Trail**: Critical operations logged

---

**This schema supports:**
- [Done] Complete academic year isolation
- [Done] Historical data preservation
- [Done] Multi-role access control
- [Done] Financial transparency
- [Done] Audit compliance
- [Done] Scalability to 5000+ students


