# Super Admin User Manual

This guide explains what a `Super Admin` can do in this SMS, what cannot be done directly, and the correct order for each major workflow.

This is written as an operator guide, not as a developer note.

## 1. Who Is the Super Admin?

The Super Admin is the highest-level user in this SMS.

The Super Admin can:

- manage school-wide settings
- manage academic structure
- manage students, enrollments, teachers, and employees
- manage finance configuration and operational finance flows
- manage timetable setup
- manage exam configuration and marks finalization
- publish results
- generate and publish admit cards
- control visibility of results and admits
- manage SMTP, signatures, and message center

In this project, the `super_admin` role has full permission coverage.

## 2. What the Super Admin Controls

The Super Admin is responsible for system-level setup before daily users can work properly.

Main control areas:

- school details
- school email credentials
- school signatures and branding
- academic years
- classes and sections
- subjects
- teacher subject assignments
- timetable setup
- students and enrollments
- employees and teachers
- exam configurations
- marks compilation and finalization
- result publishing
- admit generation and admit publishing
- fee structures, fee heads, installments, optional services, hostel fees
- fee assignment, payment, refund, ledger, holds, finance reports
- transport setup and billing
- expenses and reports
- message center

## 3. Golden Rule for Super Admin

The Super Admin can do many things, but cannot safely skip dependencies.

In this SMS, many actions depend on earlier setup. So the correct question is not only:

`Can the Super Admin do this?`

The correct question is:

`What must be created first before the Super Admin can do this properly?`

That is why this guide focuses on sequence.

## 4. Super Admin Setup Order

Recommended first-time setup order:

1. Update school details
2. Configure school signatures and branding
3. Configure SMTP and test email
4. Create academic year
5. Create classes
6. Create sections
7. Create subjects
8. Map subjects to class and academic year
9. Create exam configurations
10. Assign teachers to subjects
11. Create student records
12. Create enrollments
13. Create timetable time slots
14. Build timetable
15. Configure finance setup
16. Assign fees to enrollments
17. Start attendance, marks, payment, result, and admit workflows

If this order is ignored, later screens may open but important actions will fail.

## 5. What the Super Admin Can Do Directly

The Super Admin can directly:

- create and update school information
- upload school signatures
- save SMTP credentials
- send SMTP test email
- create academic year
- create class
- create section
- create subject
- map subject to class
- assign teacher to subject
- create students
- create enrollments
- create or update employees
- create or update teachers
- create exam configurations
- compile marks
- finalize marks
- publish results
- generate admit cards
- publish admit cards
- manage result visibility
- manage admit visibility
- create fee structures
- create fee heads
- create installments
- assign fees
- post payments
- process refunds
- view and export reports
- manage transport routes, stops, assignments, and fee cycles
- manage expenses
- use message center

## 6. What the Super Admin Cannot Do Directly

This is the most important section for training.

### 6.1 Result Publishing Cannot Be Done Directly

The Super Admin cannot directly publish result just because the Publish button exists.

The following must exist first:

- academic year
- class
- active student enrollments
- subject records
- subject-class mapping for that class and academic year
- active exam configuration for that academic year
- compiled marks available
- compiled marks finalized for the exam session or exam configuration context

If these are missing, result publishing will fail or produce incomplete data.

### 6.2 Admit Card Publishing Cannot Be Done Directly

The Super Admin cannot directly publish admit cards without a proper schedule.

Before publishing admits, the following must exist:

- exam session
- active enrollments for the class and academic year
- generated admit cards
- subject schedule snapshot for the class

Each subject in the admit schedule must have:

- exam date
- exam shift
- start time
- end time

If these are missing, admit publishing will be blocked.

### 6.3 Timetable Cannot Be Saved Randomly

The Super Admin cannot save timetable rows with invalid subject or teacher data.

Before timetable save:

- section must belong to the selected academic year
- subjects must already be mapped to the class
- assigned teacher must be a valid `teacher` or `staff` user
- teacher must not already be booked in another section for the same day and slot

### 6.4 Teacher Assignment Cannot Be Done Before Subject Mapping

The Super Admin cannot assign a teacher to a subject first and map the subject later.

Correct order:

1. create subject
2. map subject to class and academic year
3. create exam configuration if needed
4. assign teacher to subject

If class-subject mapping is missing, teacher assignment is blocked.

### 6.5 Fee Collection Cannot Start Without Enrollment Context

The Super Admin cannot properly collect fee for a student without an enrollment context.

Before payment:

- student must exist
- enrollment must exist
- fee assignment or installment structure is strongly recommended if you want meaningful due tracking and finance reporting

### 6.6 Attendance and Marks Depend on Enrollment

The Super Admin cannot expect attendance, timetable, marks, or result flows to work for a student who is not enrolled in the class and academic year.

Student record alone is not enough.

## 7. Super Admin Workflow by Module

## 7.1 School Setup

The Super Admin should do this first.

Tasks:

- update school name, address, phone, website, registration details
- upload school signatures
- configure logo and branding data
- configure SMTP email settings
- send a test email

Important:

- email delivery also depends on queue worker
- if SMTP is correct but worker is not running, emails will stay in the `jobs` table

Recommended email setup:

- first try `TLS` with port `587`
- use `SSL` with port `465` only if your server supports it cleanly

## 7.2 Academic Structure Setup

The Super Admin manages:

- academic years
- classes
- sections
- subjects
- subject mapping to class and academic year

Correct sequence:

1. create academic year
2. create class
3. create section for that academic year
4. create subject
5. map subject to class and academic year

Without subject mapping:

- marks sheet preparation will fail
- timetable save will fail for unmapped subjects
- result workflows will not complete correctly

## 7.3 Student and Enrollment Setup

Correct sequence:

1. create student
2. update student profile if needed
3. create enrollment
4. assign class and section

Important:

- `Student` is identity
- `Enrollment` is class-year placement

Without enrollment:

- no attendance context
- no finance context
- no timetable context
- no result context

## 7.4 Teacher and Staff Setup

The Super Admin can:

- create teachers
- create employees
- upload documents
- update roles and profile information

But for academic operation, teacher creation alone is not enough.

After teacher creation, the Super Admin should also:

1. map subject to class
2. create exam configuration
3. assign teacher to the subject

Only after that can teacher-based academic work happen properly.

## 7.5 Timetable Workflow

Correct order:

1. create academic year
2. create class
3. create section
4. create time slots
5. create subjects
6. map subjects to class
7. create teacher users
8. assign teachers to subjects if operationally needed
9. save timetable

The Super Admin cannot save a clean timetable if:

- subject is not mapped to class
- teacher is invalid
- teacher already has a slot conflict
- section belongs to another academic year

## 7.6 Exam Configuration Workflow

Before marks and results, the Super Admin should create exam configuration for the academic year.

This exam configuration is required by:

- marks compilation
- marks finalization
- result session creation
- teacher subject assignment flows

Without an active exam configuration:

- marks workflow becomes incomplete or blocked

## 7.7 Marks Workflow

This part is critical.

Correct order for marks:

1. academic year must exist
2. class must exist
3. section should exist for section-based academic work
3. active enrollments must exist
4. subject must be mapped to class and academic year
5. exam configuration must exist and be active
6. teacher marks may be available, or compiled marks may be entered by Super Admin
7. compiled marks must be finalized

What the Super Admin can do here:

- open marks filters
- load marks sheet
- compile marks
- finalize marks

What the Super Admin cannot do directly:

- finalize marks for a subject that is not mapped to class
- finalize marks for inactive exam configuration
- finalize marks outside the academic year date range
- finalize when no compiled rows exist

## 7.8 Result Publishing Workflow

This is the correct result workflow for Super Admin.

### Step 1

Create the foundation:

- academic year
- class
- section structure where applicable
- enrollments
- subjects
- class-subject mappings
- exam configuration

### Step 2

Prepare marks:

- teacher enters marks or admin compiles them
- admin finalizes compiled marks

### Step 3

Publish result:

- create or use exam session
- publish by rows or publish class-wise

### Step 4

After publishing:

- lock session if result should not be changed further
- use visibility controls for withheld or review cases

Important warnings:

- you cannot publish from nothing
- you cannot publish if class has no active enrollments
- you cannot publish if compiled/finalized marks are missing
- you cannot publish if subject coverage is incomplete for students
- you cannot lock a session unless it is already published

## 7.9 Admit Card Workflow

Correct order:

1. exam session must exist
2. active enrollments must exist
3. subjects must already be mapped for the class and academic year
4. admit cards must be generated
5. each subject schedule must have exam date, shift, start time, and end time
6. publish admit cards

The Super Admin can:

- generate admit cards in draft state
- regenerate admit cards if needed
- publish admit cards
- block or unhide admit cards using visibility

The Super Admin cannot directly publish admit cards if:

- no admit cards were generated
- no active students exist in that class and year
- timetable snapshot is incomplete

## 7.10 Finance Workflow

The Super Admin has both configuration authority and operational control in finance.

### Configuration tasks

- fee structures
- fee heads
- installments
- optional services
- hostel fees

### Operational tasks

- fee assignment
- discount application
- student installments
- class-wise installment assignment
- payments
- refunds
- ledger
- special fee posting
- financial holds
- reports
- expenses
- transport fee management

Correct finance order:

1. create academic year and enrollments
2. create fee setup
3. assign fees
4. review due/balance
5. record payment
6. print receipt
7. use refund only when needed

The Super Admin cannot expect due reports and ledger behavior to be meaningful if fee setup and enrollment setup are missing.

## 7.11 Transport Workflow

The Super Admin can:

- create route
- create stop
- assign transport
- bulk assign transport
- stop assignment
- generate fee cycle

Correct order:

1. create route
2. create stop
3. ensure enrollment exists
4. assign transport
5. generate transport charge cycle if needed

## 7.12 Employee and Payroll Workflow

The Super Admin can:

- create employee records
- create salary templates
- assign salary structure
- generate payroll
- finalize payroll
- mark payroll as paid
- add payroll adjustments

But correct payroll processing still depends on:

- employee records
- attendance data
- leave setup
- salary structure setup

## 8. Practical “Can and Cannot” Examples

### Example 1: Can Super Admin publish result directly?

No, not safely.

First you must:

- create subject
- map subject to class
- create exam configuration
- ensure active enrollments exist
- compile marks
- finalize marks

Then result publishing can be done.

### Example 2: Can Super Admin assign teacher directly to subject?

Not before subject-class mapping.

First:

- create subject
- map subject to class and academic year
- ensure exam configuration matches

Then teacher assignment can be saved.

### Example 3: Can Super Admin publish admit directly after creating session?

No.

First:

- generate admit cards
- complete subject schedule
- fill exam date, shift, start time, end time

Then publish.

### Example 4: Can Super Admin collect payment directly for a student record?

Not correctly without enrollment.

You need:

- student
- enrollment
- preferably fee assignment or installment setup

Then payment, receipt, and ledger will align properly.

### Example 5: Can Super Admin save timetable for any teacher and subject?

No.

The system checks:

- teacher must be a valid teacher or staff user
- subject must belong to selected class and academic year
- teacher must not already be booked in the same slot elsewhere

## 9. Daily Super Admin Checklist

At the start of the session:

1. check dashboard
2. confirm current academic year
3. confirm school details and signatures are correct
4. confirm SMTP test and email worker status if email is needed
5. confirm class, section, and subject mapping is ready
6. confirm enrollments are active

Before marks and results:

1. confirm exam configuration is active
2. confirm subject mapping exists
3. confirm enrollments exist
4. confirm marks are compiled
5. confirm marks are finalized

Before admit publishing:

1. confirm exam session exists
2. confirm admit cards are generated
3. confirm all subject schedule rows are complete

Before fee collection period:

1. confirm fee setup is complete
2. confirm enrollments are correct
3. confirm payment and receipt flow is working

## 10. Super Admin Mistakes to Avoid

- creating students but forgetting enrollments
- creating subjects but not mapping them to class
- assigning teachers before class-subject mapping
- trying to finalize marks before compile step
- trying to publish results before finalization
- trying to publish admits before completing exam schedule
- collecting payments before fee structure and assignment are ready
- enabling SMTP without running the email worker

## 11. Best Practice for Super Admin

Use this operating model:

- first configure the school
- then configure academics
- then configure staff and teacher assignments
- then create students and enrollments
- then configure timetable
- then configure exams and marks
- then publish admits and  results
- then run finance and reporting

This order keeps the system stable and reduces failed workflows.

## 12. Summary

The Super Admin is not just a high-permission user.

The Super Admin is the setup owner of the entire school workflow.

The biggest responsibility of this role is understanding that:

- many actions are allowed
- but not every action can be done first
- the system expects setup in the correct order

If the Super Admin follows the correct sequence, the rest of the school roles can work smoothly.
