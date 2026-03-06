<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffAttendanceRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_id',
        'staff_attendance_session_id',
        'attendance_date',
        'status',
        'source',
        'approval_status',
        'late_minutes',
        'remarks',
        'created_by',
        'updated_by',
        'approved_by',
        'approved_at',
        'override_reason',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'approved_at' => 'datetime',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(StaffAttendanceSession::class, 'staff_attendance_session_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
