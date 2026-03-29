# School Management System User Guide

This document is a copyable, role-based operating guide for the current School Management System.

It is written for daily users of the live system, not for developers.

Primary role sections in this guide:

- Super Admin
- Teacher
- Student
- Accountant

This guide reflects the current Laravel + Angular system in this repository, including:

- authentication and password reset
- role-based navigation
- in-app notifications and bell badge
- student and enrollment workflows
- attendance
- subject and teacher assignment
- timetable
- finance and receipts
- expense management
- admit cards
- published results
- school credentials and queued email delivery
- recent dashboard visibility for teacher attendance activity

## 1. System Overview

The School Management System is built around one main idea:

- `Student` is the permanent person record
- `Enrollment` is the academic-year-specific placement

Most academic, attendance, result, admit, and finance operations depend on a valid enrollment.

Main operating areas in the system:

- Dashboard
- Notifications
- Students
- Enrollments
- Employees and Teachers
- Attendance
- Academic Years, Classes, Sections, Subjects
- Subject-Class Mapping and Teacher Assignment
- Timetable
- Exam Configuration
- Marks and Published Results
- Admit Cards
- Finance
- Expenses
- HR Payroll
- School Signatures
- School Credentials
- Message Center
- Events
- Audit and Downloads

## 2. Login and Access Basics

Users sign in with their authorized account from the login screen.

Important rules:

- each user sees only the modules allowed for their role
- some actions are visible only after setup dependencies are complete
- password reset is available for eligible active staff and admin accounts
- student and parent access is portal-focused, not admin-focused

If login fails, check:

- email spelling
- password spelling
- active account status
- correct role assignment

## 3. Daily Operating Checklist

Before school operations begin, confirm:

1. backend and frontend are available
2. database is connected
3. current academic year is correct
4. the email queue worker is running if email delivery is required
5. school credentials are valid if SMTP notifications are needed

For production email processing, keep the email worker running continuously:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\start-email-worker.ps1
```

If the worker is not running:

- payment and event notification jobs can stay in the `jobs` table
- email may be delayed even if the business action already succeeded

## 4. Core Concepts All Roles Should Understand

### 4.1 Student vs Enrollment

Use this rule always:

- `Student` = identity, profile, admission details
- `Enrollment` = class, section, academic year, roll placement

Without enrollment:

- no class-based attendance
- no proper fee context
- no timetable context
- no result context
- no admit card context

### 4.2 Published Data vs Working Data

Some data is operational and can change.

Examples:

- student profile
- teacher assignment
- fee setup
- attendance entries

Some data is publication-oriented and should be treated carefully.

Examples:

- finalized marks
- published results
- generated admit cards
- payroll finalization

### 4.3 Queue-Based Emails

The system uses queued email delivery for many operational messages.

Examples:

- payment recorded
- student registration
- student profile updates
- admit notifications
- result notifications
- message center emails

Email has two parts:

1. create the queue job
2. process the queue job through the worker

SMTP can be valid but delivery can still wait if the worker is not running.

### 4.4 In-App Notifications

The system also includes in-app notifications inside the web application.

Users may see notifications in:

- the header bell badge
- the recent notification dropdown
- the full Notifications page
- the dashboard notification area for recent activity

Common notification examples:

- teacher attendance was marked
- teacher self-attendance punch in or punch out happened
- operational reminders or recent system activity appears for the logged-in user

How to use notifications:

1. click the bell icon to open recent notifications
2. open a notification to go to the related screen when a target is available
3. use the Notifications page to review older items
4. use mark-read or mark-all-read actions to clear unread items

Important note:

- in-app notifications are helpful operational alerts, but the underlying module page remains the source of truth

## 5. Super Admin Guide

The Super Admin is the full system owner.

This role is responsible for setup, sequencing, governance, and high-level operational control.

### 5.1 Main Areas Available to Super Admin

The Super Admin can access:

- Dashboard
- Notifications
- Students
- Enrollments
- Employees
- Teachers
- Attendance
- Academic Years
- Classes
- Sections
- Subjects
- Class Subject Assign
- Subject Teacher Assign
- Exam Configuration
- Admin Assign Marks
- Published Results
- Admit Cards
- Timetable
- Finance
- HR Payroll
- Expenses
- Signature Upload
- Credentials
- Send Message
- Events
- Audit and Downloads

### 5.2 Recommended Setup Order

For a new school or new academic cycle, use this order:

1. update school details
2. upload school signatures
3. configure SMTP credentials and test email
4. create academic year
5. create classes
6. create sections
7. create subjects
8. map subjects to classes and academic year
9. create exam configurations
10. create teacher and staff accounts
11. assign teachers to subjects
12. create student records
13. create enrollments
14. configure timetable
15. configure finance masters
16. assign fees and installments
17. start attendance, payment, marks, admit, and result workflows

If this order is skipped, later modules may open but actions can fail or produce incomplete results.

### 5.3 Student Management

Super Admin can:

- create student records
- edit student profiles
- view financial summary
- download student PDFs
- view academic history

Best practice:

1. create the student record
2. complete profile details
3. add guardian and contact information
4. create enrollment
5. verify class and section

### 5.4 Enrollment Management

Super Admin can:

- create enrollment
- update enrollment
- promote student
- repeat student
- transfer student
- view academic history chain

Use enrollment actions carefully because they affect:

- attendance scope
- finance scope
- timetable scope
- result eligibility
- admit eligibility

### 5.5 Academic Structure

Super Admin can manage:

- academic years
- classes
- sections
- subjects
- subject-to-class mappings
- teacher assignments

Correct dependency:

1. create subject
2. map subject to class and academic year
3. assign teacher to subject

Do not assign teachers before subject-class mapping exists.

### 5.6 Attendance Oversight

Super Admin can:

- mark attendance
- view section attendance
- view student attendance
- view section statistics
- use attendance search and live search
- download monthly and session reports
- lock attendance
- see teacher attendance activity through the dashboard notification area and bell menu

When a teacher:

- marks student attendance
- marks self attendance punch in
- marks self attendance punch out

the super admin and school admin can now see that action as a recent in-app notification.

### 5.6A Notification Workflow for Super Admin

The super admin should use notifications as a quick oversight tool.

Best use:

1. watch the bell badge for unread items
2. open recent items from the dropdown during the day
3. use the full Notifications page for unread or historical review
4. follow the notification link to the related screen when investigation is needed

Typical items the super admin may see:

- teacher attendance marking activity
- teacher self-attendance activity
- other recent in-app alerts assigned to that user

Attendance depends on:

- active enrollments
- valid academic year
- valid class or section context

### 5.7 Timetable Management

Super Admin can:

- configure timetable
- assign subject and teacher combinations
- manage section timetable
- monitor teacher conflicts

Before saving timetable:

- sections must exist
- subjects must already be mapped to class
- teacher must be valid teacher or staff
- teacher must not be double-booked in the same time slot

### 5.8 Exam Configuration, Marks, and Results

Super Admin can:

- create exam configurations
- review marks filters and marks sheets
- compile marks
- finalize marks
- create result sessions
- publish results
- publish class-wise results
- lock and unlock result sessions
- manage visibility of student results

Required order:

1. subject mapping must exist
2. enrollments must exist
3. exam configuration must exist
4. teacher marks or compiled marks must exist
5. marks must be finalized
6. result publishing can happen

Result publishing should not be treated as the first step.

### 5.9 Admit Card Management

Super Admin can:

- list exam sessions
- generate draft admit cards
- publish session admit cards
- download individual admit cards
- download session paper PDFs
- manage admit visibility

Before admit publication:

- exam session must exist
- eligible students must be enrolled
- admit generation must already be done
- exam schedule details must be complete

### 5.10 Finance and Fee Operations

Super Admin has full finance authority.

Key finance capabilities:

- fee heads
- fee installments
- fee structure and optional services
- fee assignment
- student and class installment assignment
- payments
- refunds
- receipt viewing
- receipt HTML generation
- unified receipt context
- ledger access
- ledger reversal
- special fee posting
- financial holds
- finance reports
- transport charge management

Important rule:

- payment save success and email success are different things
- accounting writes are synchronous
- email notifications are asynchronous

So if a payment is saved but the email is delayed, the finance transaction is still valid.

### 5.11 Expenses

Super Admin can:

- create expenses
- reverse expenses
- upload receipts
- open receipt files
- run expense audit reports
- download expense entries

### 5.12 HR Payroll

Super Admin can:

- mark attendance for payroll context
- lock and unlock attendance month
- review leave and leave balance
- create salary templates
- assign salary structure
- generate payroll
- finalize payroll
- mark payroll paid
- add adjustments

### 5.13 School Credentials and Messaging

Super Admin can:

- save SMTP credentials
- test SMTP
- view queue health
- upload signatures
- use message center
- manage event emails
- review audit and download trails

If emails are not going out:

- first check SMTP test
- then check queue worker
- then check `jobs` and `failed_jobs`

### 5.14 Super Admin Daily Checklist

Use this every day:

1. confirm dashboard loads
2. confirm current academic year
3. confirm email worker status if notifications matter
4. confirm credentials are healthy if SMTP is enabled
5. confirm bell notifications and recent dashboard alerts are updating
6. confirm students and enrollments are visible
7. confirm finance and receipt flows are working
8. confirm result and admit sessions only when setup is complete

### 5.15 Super Admin Common Mistakes

Avoid these:

- creating students but not creating enrollments
- creating subjects but not mapping them
- assigning teachers before mapping subjects
- publishing results before finalizing marks
- publishing admits before full scheduling
- assuming queued emails send automatically without worker

## 6. Teacher Guide

The Teacher role is focused on operational academic work.

### 6.1 Main Areas Available to Teacher

Teacher navigation is intentionally focused.

Teacher can access:

- Dashboard
- Allotted Subjects
- Assign Marks
- Download Results
- Mark Attendance
- Assigned Timetable

### 6.2 What the Teacher Role Is Expected to Do

A teacher mainly works in:

- attendance entry
- marks entry
- assigned subject review
- timetable review
- result paper viewing where allowed

This role does not control system setup.

### 6.3 Allotted Subjects

Teachers can use the subject area to:

- see which subjects are assigned to them
- understand class and section context
- verify subject allocation before marks or attendance work

If the expected subject is not visible:

- the subject may not be mapped to the class
- the teacher may not be assigned yet
- the academic year context may be wrong

### 6.4 Mark Attendance

Teacher can:

- open attendance assignments
- load attendance sheet
- submit attendance for the allowed section/subject context

Before attendance can be marked:

- teacher assignment must exist
- students must have active enrollment
- the date and sheet context must be valid

If no rows appear:

- check assignment
- check enrollment
- check section or academic year filters

Operational note:

- when a teacher marks attendance successfully, that action can appear on the super admin/school admin dashboard as a recent in-app notification

### 6.5 Assign Marks

Teacher can:

- load marks sheet
- enter subject-wise marks
- save marks for assigned students

Teacher usually does not finalize results directly.

The normal flow is:

1. teacher enters marks
2. admin reviews or compiles
3. super admin finalizes
4. super admin publishes

### 6.6 Assigned Timetable

Teacher can:

- view assigned timetable
- understand period schedule
- confirm class/section/day allocation

This is read-focused from the teacher side.

If timetable is blank:

- timetable may not be configured
- teacher may not be assigned in rows
- academic year or section mapping may be incomplete

### 6.7 Download Results

Teacher can access published results in the teacher workflow where the role and permissions allow it.

Typical use:

- review already published result data
- open result paper or published lists

This is not the same as result publishing control.

### 6.8 Teacher Daily Checklist

Use this daily:

1. check allotted subjects
2. check assigned timetable
3. mark attendance on time
4. enter marks only for assigned classes
5. report missing students or wrong class mapping quickly

Additional note:

- teacher self-attendance punch in/out also creates a recent in-app notification for super admin and school admin visibility

### 6.9 Teacher Common Problems

Problem: attendance sheet empty

Check:

- teacher assignment
- active enrollment
- selected class or section

Problem: marks sheet not loading

Check:

- subject mapping exists
- exam configuration exists
- class/section/academic year filters are correct

Problem: timetable not visible

Check:

- teacher is assigned in timetable rows
- current year and teacher context are correct

## 7. Student Guide

The Student role is portal-based.

### 7.1 Main Areas Available to Student

Student can access:

- Dashboard
- Notifications
- Admit Card
- Fee
- Result
- Timetable
- Academic History
- Attendance History

### 7.2 What the Student Portal Is For

The student portal is for visibility, not admin data entry.

The student uses it to:

- check notifications for recent updates
- check fees
- see published result data
- download admit card
- view timetable
- review academic history
- review attendance history

### 7.3 Admit Card

Student can:

- view latest published admit card
- download admit card if available

If no admit card appears, common reasons are:

- admit not generated yet
- admit generated but not published
- admit visibility is restricted

### 7.4 Fee Section

Student can review the fee area to understand:

- charges
- payments
- balances
- due status

This is visibility-focused and helps reduce confusion around collection and outstanding amounts.

### 7.5 Result Section

Student can:

- open published results
- review status and marks where available

If no result appears:

- result may not be published yet
- visibility may be withheld
- marks may still be under processing

### 7.6 Timetable

Student can:

- see class timetable
- review periods, subjects, and teacher details where available

### 7.7 Academic History

Student can:

- review prior enrollment-related academic history
- track movement across classes or academic years

### 7.8 Attendance History

Student can:

- view attendance trends and recorded attendance history

If attendance looks incorrect, student should report it to class teacher or school administration.

### 7.8A Notifications for Student

A student can use the Notifications area to review recent portal updates.

Typical use:

- open the bell icon to see recent unread items
- open the Notifications page for a longer list
- check notifications before looking for results, admit cards, or fee changes

If something mentioned in a notification is not visible yet:

- refresh the destination page once
- confirm the item is actually published for student visibility
- contact school administration if the problem continues

### 7.9 Student Daily Checklist

A student can use this quick routine:

1. check timetable for the day or week
2. check notifications for recent updates
3. check attendance history if absence concerns exist
4. check fee status before collection deadlines
5. check result after publication announcements
6. download admit card before exams

### 7.10 Student Common Problems

Problem: result not visible

Possible reasons:

- result not published
- result withheld
- login is not linked to the correct student record

Problem: admit card missing

Possible reasons:

- not generated
- not published
- visibility restricted

Problem: fee data looks incomplete

Possible reasons:

- recent payment not yet reflected in refreshed view
- enrollment context mismatch
- fee assignment not complete for that cycle

## 8. Accountant Guide

The Accountant role is focused on fee operations, reporting, expenses, and finance-linked workflows.

### 8.1 Main Areas Available to Accountant

Accountant can access:

- Dashboard
- Finance
- HR Payroll
- Expenses
- Signature Upload

Depending on permission structure, this role usually works with finance views and manage actions rather than school-wide academic setup.

### 8.2 What the Accountant Role Covers

Accountant is typically responsible for:

- collecting fees
- recording receipts
- checking balances
- processing refunds
- reviewing ledger
- watching due and collection reports
- tracking transport charges
- managing expense records
- participating in payroll visibility or operational finance support

### 8.3 Finance Module

The Finance module includes:

- fee heads
- installments
- fee assignment context
- payment recording
- refund processing
- receipts
- ledger
- student balance
- enrollment balance
- class ledger
- finance reports
- transport charge view
- holds and special fee actions where permitted

### 8.4 Recording a Payment

Typical payment flow:

1. search student or enrollment
2. verify class, section, and due context
3. enter amount, date, method, remarks, and transaction reference if needed
4. save payment
5. confirm receipt number
6. print or open receipt if needed

What happens in the system:

- payment is posted
- ledger is updated
- audit logs are written
- email notification may be queued

If the email does not arrive immediately but receipt is created, the payment still succeeded.

### 8.5 Refunds

Accountant can process refunds where permissions allow.

Before refund:

- verify original payment
- verify refund reason
- verify the amount and reference

After refund:

- refund payment entry is created
- corresponding ledger update is posted
- audit trail is preserved

Refunds should never be used as a casual correction tool. Confirm the reason first.

### 8.6 Receipts and Ledger

Accountant can:

- open receipts
- generate printable HTML receipt
- open unified receipt context for enrollment
- view student ledger
- view class ledger
- download ledger reports
- review balances

Use ledger when:

- payment disputes happen
- a student says an amount is pending or overpaid
- class-level collection summary is needed

### 8.7 Due and Collection Reports

Key finance reports include:

- fees due
- fees collection
- transport route-wise report
- ledger reports

Before exporting reports, verify:

- academic year
- class
- section
- date range
- report type

### 8.8 Expenses

Accountant can:

- create expense entry
- reverse expense
- upload expense receipt
- download receipt file
- open expense audit report
- download expense entries

Use expense module for controlled finance tracking, not for ad hoc notes outside the system.

### 8.9 HR Payroll View

Accountant may also participate in payroll-linked workflows depending on permission design.

Possible access includes:

- payroll list
- payroll batch visibility
- period options
- salary and payout review
- paid marking or payroll support actions where allowed

### 8.10 Signature Upload

This role may access signature upload for finance or document generation support.

Use it carefully because signatures affect official output like reports and printed artifacts.

### 8.11 Accountant Daily Checklist

Use this each day:

1. verify finance dashboard and student search are working
2. confirm receipts generate correctly
3. confirm ledger and balances are loading
4. confirm expense uploads are working
5. confirm email worker is running if receipt or payment notifications depend on email

### 8.12 Accountant Common Problems

Problem: payment saved but no email received

Check:

- payment record exists
- email worker is running
- SMTP is healthy

Problem: student balance looks wrong

Check:

- correct enrollment selected
- refunds or reversals already posted
- ledger entries match the student and academic cycle

Problem: receipt issue

Check:

- payment exists
- receipt number exists
- browser download or popup handling

## 9. Password Reset Guide

Forgot password shows a generic success message for security.

That means:

- if the account is eligible, a reset link is created and sent
- if the account is not eligible or not found, the same success text may still appear

Reset works only for eligible active staff and admin accounts.

If a user says "I did not get the reset email", check:

1. exact email spelling
2. whether the email exists in the users table
3. whether the role is eligible
4. whether the account is active
5. whether a reset token was created
6. whether SMTP and queue delivery are healthy

## 10. Email and Queue Operations

### 10.1 SMTP Test vs Real Event Delivery

These are different:

- SMTP test email sends immediately from the credentials screen
- payment and many event emails go through the queue

So a successful SMTP test does not guarantee queued event delivery if the worker is stopped.

### 10.2 Required Conditions for Delivery

To send queued email successfully:

- SMTP must be enabled
- host, port, username, password, and sender must be valid
- queue worker must be running
- server must be allowed to connect to the SMTP port

### 10.3 Recommended Production Practice

For production:

- keep web server separate from queue worker
- configure the email worker as a background startup task or service
- monitor `jobs` and `failed_jobs`

Windows production startup command:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\start-email-worker.ps1
```

## 11. Troubleshooting Quick Reference

### 11.1 User Cannot See Expected Module

Check:

- role assignment
- account status
- whether the module is role-restricted

### 11.2 Student Exists but Nothing Academic Works

Usually means enrollment is missing.

### 11.3 Teacher Cannot Mark Attendance or Marks

Check:

- teacher assignment
- subject mapping
- academic year context
- section and enrollment setup

### 11.4 Payment Is Recorded but Notification Is Missing

Check:

- payment exists
- if this is an in-app notification, refresh bell/dashboard and confirm the `user_notifications` table has rows
- if this is an email notification, confirm the queue worker is running
- if this is an email notification, confirm SMTP credentials are valid
- if this is an email notification, confirm no backlog in `jobs`
- if this is an email notification, confirm no error in `failed_jobs`

### 11.5 Bell Notification Is Not Updating

Check:

- the user is logged in to the correct account
- the new notification APIs return data
- frontend polling is active
- `php artisan migrate` has created the `user_notifications` table
- config/cache has been cleared after recent environment changes

### 11.6 Reset Link Not Received

Check:

- exact email spelling
- eligible role
- active account status
- reset token creation
- SMTP and queue delivery

## 12. Suggested Training Sequence

When onboarding a school, train in this order:

1. Super Admin first
2. Accountant second
3. Teacher third
4. Student portal fourth

Reason:

- Super Admin sets the foundation
- Accountant depends on enrollment and finance setup
- Teacher depends on subjects, assignments, and timetable
- Student depends on publication and visibility

## 13. File References

- [README.md](D:/laravel%20project/sms/README.md)
- [SUPER_ADMIN_GUIDE.md](D:/laravel%20project/sms/SUPER_ADMIN_GUIDE.md)
- [DEPLOY.md](D:/laravel%20project/sms/DEPLOY.md)
- [routes/api.php](D:/laravel%20project/sms/routes/api.php)
- [frontend/src/app/app.routes.ts](D:/laravel%20project/sms/frontend/src/app/app.routes.ts)
- [scripts/start-email-worker.ps1](D:/laravel%20project/sms/scripts/start-email-worker.ps1)
- [storage/logs/laravel.log](D:/laravel%20project/sms/storage/logs/laravel.log)

## 14. Final Operating Rule

The system works best when users remember this:

- setup first
- enrollment second
- operations third
- publication after validation
- queue worker always on if email matters

That single rule prevents most real-world operational confusion in this SMS.
