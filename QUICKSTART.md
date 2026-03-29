# Quick Start Guide - School Management System API

## 🚀 5-Minute Setup

### Step 1: Installation
```bash
# Install dependencies
composer install

# Setup environment
cp .env.example .env
php artisan key:generate

# Configure database in .env
DB_DATABASE=school_management
DB_USERNAME=root
DB_PASSWORD=your_password
```

### Step 2: Database
```bash
# Create database
mysql -u root -p
CREATE DATABASE school_management;
exit;

# Run migrations
php artisan migrate
```

Important:

- `php artisan migrate` now also creates the `user_notifications` table used by the bell badge, recent notifications dropdown, full notifications page, and super admin visibility for teacher attendance activity.

### Step 3: Start Server
```bash
php artisan serve
# API available at: http://localhost:8000/api/v1
```

## 📝 First API Calls

### 1. Create Admin User (via Tinker)
```bash
php artisan tinker
```
```php
$user = \App\Models\User::create([
    'email' => 'admin@school.com',
    'password' => bcrypt('password'),
    'role' => 'school_admin',
    'first_name' => 'Admin',
    'last_name' => 'User',
    'status' => 'active'
]);
```

### 2. Login
```bash
curl -X POST http://localhost:8000/api/v1/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@school.com",
    "password": "password"
  }'
```

Save the token from response!

### 3. Create Academic Year
```bash
curl -X POST http://localhost:8000/api/v1/academic-years \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "2024-2025",
    "start_date": "2024-04-01",
    "end_date": "2025-03-31",
    "is_current": true,
    "status": "active"
  }'
```

### 4. Create Class
```bash
curl -X POST http://localhost:8000/api/v1/classes \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Class 1",
    "numeric_order": 1,
    "status": "active"
  }'
```

### 5. Create Section
```bash
curl -X POST http://localhost:8000/api/v1/sections \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "class_id": 1,
    "academic_year_id": 1,
    "name": "A",
    "capacity": 40
  }'
```

### 6. Add Student
```bash
curl -X POST http://localhost:8000/api/v1/students \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "John",
    "last_name": "Doe",
    "email": "john.doe@example.com",
    "password": "student123",
    "admission_number": "2024001",
    "admission_date": "2024-04-01",
    "date_of_birth": "2012-01-15",
    "gender": "male"
  }'
```

### 7. Enroll Student
```bash
curl -X POST http://localhost:8000/api/v1/enrollments \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "student_id": 1,
    "academic_year_id": 1,
    "section_id": 1,
    "roll_number": 1,
    "enrollment_date": "2024-04-01"
  }'
```

## 🎯 Common Workflows

### Daily Attendance Flow
1. Get section students: `GET /api/v1/attendance/section?section_id=1&date=2024-02-02`
2. Mark attendance: `POST /api/v1/attendance/mark`
3. View statistics: `GET /api/v1/attendance/section/statistics`
4. Search student report targets: `GET /api/v1/attendance/reports/search?student_id=2024001`
5. Realtime report search: `GET /api/v1/attendance/reports/live-search?q=2024001&academic_year_id=1&month=4`
6. Monthly report download (session-driven): `GET /api/v1/attendance/reports/monthly/download?student_ids=1,2&academic_year_id=1&month=4`
7. Session-wise report download: `GET /api/v1/attendance/reports/session/download?student_ids=1,2`
8. Bulk class monthly preview: `GET /api/v1/attendance/reports/bulk/monthly?class_ids=1,2&academic_year_id=1&month=4`
9. Bulk class monthly Excel CSV: `GET /api/v1/attendance/reports/bulk/monthly/download?class_ids=1,2&academic_year_id=1&month=4`

### Exam Flow
1. Create exam: `POST /api/v1/exams`
2. Create schedule: `POST /api/v1/exams/{id}/schedule`
3. Enter marks: `POST /api/v1/results/enter`
4. Generate report card: `GET /api/v1/results/enrollment/{id}/report-card`

### Fee Collection Flow
1. View fee assignment: `GET /api/v1/fees/assignments/{enrollmentId}`
2. Record payment (canonical): `POST /api/v1/finance/payments`
3. Print receipt: `GET /api/v1/finance/payments/{id}/receipt`
4. Legacy compatibility endpoint (still supported): `POST /api/v1/finance/receipts`

### In-App Notification Flow
1. Check unread count: `GET /api/v1/notifications/unread-count`
2. Load recent items: `GET /api/v1/notifications/recent`
3. Open paginated list: `GET /api/v1/notifications`
4. Mark one as read: `POST /api/v1/notifications/{id}/read`
5. Mark all as read: `POST /api/v1/notifications/mark-all-read`

### Student Ledger (Clear + Download)
1. View clear ledger statement:
   `GET /api/v1/finance/students/{studentId}/ledger`
2. Optional filters:
   `?academic_year_id=1&start_date=2026-04-01&end_date=2026-12-31&reference_type=payment`
3. Download ledger as CSV:
   `GET /api/v1/finance/students/{studentId}/ledger/download`
4. Download with filters:
   `GET /api/v1/finance/students/{studentId}/ledger/download?academic_year_id=1&start_date=2026-04-01&end_date=2026-12-31`

### Class-Wise Ledger (All Students + Download)
1. View class ledger summary:
   `GET /api/v1/finance/classes/{classId}/ledger`
2. Download class ledger CSV:
   `GET /api/v1/finance/classes/{classId}/ledger/download`
3. Optional filters:
   `?academic_year_id=1&start_date=2026-04-01&end_date=2026-12-31`
4. CSV columns include:
   `ledger_serial_number, student_name, father_name, class, phone_number, enrollment_id, debits, credits, balance`

### Year-End Promotion Flow
1. Review student performance: `GET /api/v1/students/{id}/academic-history`
2. Promote student: `POST /api/v1/enrollments/{id}/promote`
3. OR repeat student: `POST /api/v1/enrollments/{id}/repeat`

## 🔑 Key Concepts

### Student vs Enrollment
- **Student**: Permanent identity (like Aadhar)
- **Enrollment**: Year-specific placement (changes annually)

### Attendance Monthly Reports (Important)
- Monthly report APIs are session-driven.
- Send `month` + `academic_year_id`.
- Do not send a separate `year`; backend resolves year from the academic session range.

### Locked Records
- Locked enrollments = Past academic years (read-only)
- Locked attendance = Cannot be edited after cutoff time
- Locked results = Finalized marks

### Financial Hold vs Academics
- Financial hold ≠ Block academics
- Students can attend class with pending dues
- Services blocked: TC, certificates, report card access

## 📊 Testing with Postman

Import this collection:
```json
{
  "info": {
    "name": "School Management System",
    "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json"
  },
  "item": [
    {
      "name": "Auth",
      "item": [
        {
          "name": "Login",
          "request": {
            "method": "POST",
            "url": "{{base_url}}/api/v1/login",
            "body": {
              "mode": "raw",
              "raw": "{\n  \"email\": \"admin@school.com\",\n  \"password\": \"password\"\n}"
            }
          }
        }
      ]
    }
  ]
}
```

Set environment variable:
- `base_url`: http://localhost:8000
- `token`: (paste your token after login)

## 🐛 Troubleshooting

### "SQLSTATE[42S02]: Base table or view not found"
```bash
php artisan migrate:fresh
```

### "Unauthenticated"
- Check if token is included in Authorization header
- Verify token is not expired
- Ensure Bearer prefix: `Bearer YOUR_TOKEN`

### "This action is unauthorized"
- Check user role permissions
- Verify middleware is configured correctly

### Foreign key constraint error
- Ensure parent records exist before creating child records
- Check the order of data creation

### Tests failing with sqlite driver error
- Test bootstrap now auto-selects DB:
- `pdo_sqlite` available -> `sqlite :memory:`
- otherwise -> MySQL fallback (`sms_test`)
- Optional explicit override:
```bash
TEST_DB_CONNECTION=mysql TEST_DB_DATABASE=sms_test php artisan test
```

## Durability Ops (Backup + Restore Drill)

### Manual run
```bash
php artisan ops:backup-db
php artisan ops:restore-drill
```

### Scheduled run
- Daily backup: `02:00` (`ops:backup-db`)
- Weekly restore verification: Sunday `03:00` (`ops:restore-drill`)
- Ensure scheduler is active:
```bash
php artisan schedule:work
```

## 📚 Next Steps

1. ✅ Create sample data using seeders
2. ✅ Set up frontend (Vue.js/Flutter)
3. ✅ Configure CORS for frontend
4. ✅ Implement remaining controllers
5. ✅ Add automated tests
6. ✅ Deploy to production

## 💡 Pro Tips

1. **Use transactions**: Wrap multiple operations in DB transactions
2. **Validate early**: Use Form Requests for complex validation
3. **Cache queries**: Cache frequently accessed data
4. **Index properly**: Ensure foreign keys and search fields are indexed
5. **Log everything**: Use audit logs for compliance

## 📞 Need Help?

- Check README.md for detailed documentation
- Review API routes in routes/api.php
- Examine models for relationships
- Read migration files for schema details

---

**Happy Coding! 🎉**
