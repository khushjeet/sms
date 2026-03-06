<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Staff extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'staff';

    protected $fillable = [
        'user_id',
        'employee_id',
        'joining_date',
        'employee_type',
        'designation',
        'department',
        'qualification',
        'salary',
        'date_of_birth',
        'gender',
        'address',
        'emergency_contact',
        'aadhar_number',
        'pan_number',
        'status',
        'resignation_date',
    ];

    protected $casts = [
        'joining_date' => 'date',
        'date_of_birth' => 'date',
        'resignation_date' => 'date',
        'salary' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(TeacherDocument::class)->latest('id');
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(StaffAttendanceRecord::class);
    }

    public function attendanceSessions(): HasMany
    {
        return $this->hasMany(StaffAttendanceSession::class);
    }

    public function attendancePunchEvents(): HasMany
    {
        return $this->hasMany(StaffAttendancePunchEvent::class);
    }

    public function leaveLedgerEntries(): HasMany
    {
        return $this->hasMany(LeaveLedgerEntry::class);
    }

    public function salaryStructures(): HasMany
    {
        return $this->hasMany(StaffSalaryStructure::class);
    }
}
