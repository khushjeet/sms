# Promotion History Tracking with `promoted_from_enrollment_id`

## Overview

The `promoted_from_enrollment_id` field in the `enrollments` table creates a self-referential relationship that tracks the academic progression of students through their school journey. This allows the system to maintain a complete history of how a student moved from one enrollment to another.

## Database Structure

```sql
promoted_from_enrollment_id (nullable, FK → enrollments.id, onDelete='set null')
```

- **Nullable**: New enrollments (first-time admissions) don't have a previous enrollment
- **Self-referential**: Points to another record in the same `enrollments` table
- **Soft delete handling**: If the previous enrollment is deleted, this field is set to `null` (preserves data integrity)

## How It Works

### 1. **Promotion** (`promote()` method)
When a student is promoted to the next class:
- The current enrollment status is set to `'promoted'` and locked
- A new enrollment is created with `promoted_from_enrollment_id` pointing to the previous enrollment
- This creates a chain: `Enrollment A → Enrollment B → Enrollment C`

**Example:**
```
Class 1 (2023-24) → promoted → Class 2 (2024-25)
```

### 2. **Repeat** (`repeat()` method)
When a student repeats a class:
- The current enrollment status is set to `'repeated'` and locked
- A new enrollment is created with `promoted_from_enrollment_id` pointing to the previous enrollment
- This maintains the history even for repeated classes

**Example:**
```
Class 1 (2023-24) → repeated → Class 1 (2024-25)
```

### 3. **New Admission** (`store()` method)
When a student is newly admitted:
- `promoted_from_enrollment_id` is `null` (no previous enrollment)
- This marks the start of the academic history chain

### 4. **Transfer** (`transfer()` method)
When a student is transferred:
- `promoted_from_enrollment_id` is NOT set (transfer is different from promotion)
- The enrollment status is set to `'transferred'` and locked
- No new enrollment is created (student leaves the school)

## Model Relationships

### Enrollment Model

```php
// Get the enrollment this was promoted from
public function promotedFromEnrollment(): BelongsTo
{
    return $this->belongsTo(Enrollment::class, 'promoted_from_enrollment_id');
}

// Get the enrollment this was promoted to (if exists)
public function promotedToEnrollment(): HasOne
{
    return $this->hasOne(Enrollment::class, 'promoted_from_enrollment_id');
}
```

## Helper Methods

### 1. `getAcademicHistoryChain()`
Returns the complete academic history from the first enrollment to the current (and future) enrollments.

```php
$enrollment->getAcademicHistoryChain();
// Returns: [Enrollment1, Enrollment2, Enrollment3, ...]
```

### 2. `isPromoted()`
Checks if this enrollment was promoted from another enrollment.

```php
$enrollment->isPromoted(); // true/false
```

### 3. `hasBeenPromoted()`
Checks if this enrollment has been promoted to another enrollment.

```php
$enrollment->hasBeenPromoted(); // true/false
```

### 4. `getOriginalEnrollment()`
Gets the first enrollment in the chain (the original admission).

```php
$enrollment->getOriginalEnrollment(); // Returns first Enrollment or null
```

## API Endpoints

### Get Enrollment Details
```
GET /api/v1/enrollments/{id}
```
Returns enrollment with `promotedFromEnrollment` and `promotedToEnrollment` relationships loaded.

### Get Academic History Chain
```
GET /api/v1/enrollments/{id}/academic-history
```
Returns the complete academic history chain for an enrollment.

**Response:**
```json
{
  "current_enrollment_id": 5,
  "history": [
    {
      "id": 1,
      "academic_year": "2022-23",
      "class": "Class 1",
      "section": "A",
      "status": "promoted",
      "enrollment_date": "2022-04-01"
    },
    {
      "id": 3,
      "academic_year": "2023-24",
      "class": "Class 2",
      "section": "B",
      "status": "promoted",
      "enrollment_date": "2023-04-01"
    },
    {
      "id": 5,
      "academic_year": "2024-25",
      "class": "Class 3",
      "section": "A",
      "status": "active",
      "enrollment_date": "2024-04-01"
    }
  ],
  "total_enrollments": 3
}
```

## Use Cases

### 1. **Academic Reports**
Generate reports showing a student's complete academic journey from admission to current class.

### 2. **Promotion Verification**
Verify that a student was properly promoted from a previous class before allowing promotion to the next.

### 3. **Fee History**
Track fee payments across multiple academic years and enrollments.

### 4. **Transfer Certificate**
Generate transfer certificates showing the complete academic history.

### 5. **Analytics**
Analyze promotion rates, repeat rates, and student progression patterns.

## Data Integrity

- **Cascade Protection**: When an enrollment is deleted, the `promoted_from_enrollment_id` in child enrollments is set to `null` (prevents orphaned references)
- **Status Tracking**: Each enrollment maintains its status (`active`, `promoted`, `repeated`, `transferred`, `dropped`)
- **Locking**: Promoted/repeated/transferred enrollments are locked to prevent modifications

## Example Scenarios

### Scenario 1: Normal Progression
```
Year 1: Class 1 → promoted → Year 2: Class 2 → promoted → Year 3: Class 3
```

### Scenario 2: Repeat
```
Year 1: Class 1 → repeated → Year 2: Class 1 → promoted → Year 3: Class 2
```

### Scenario 3: Transfer
```
Year 1: Class 1 → promoted → Year 2: Class 2 → transferred (no new enrollment)
```

## SRS Compliance

This implementation aligns with SRS requirements for:
- **Section 12.1**: Academic Year Transition
- **Section 3.2**: Student Enrollment Management
- **Section 4.1**: Academic Progression Tracking
- **Section 7.1**: Historical Data Maintenance
