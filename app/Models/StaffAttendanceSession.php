<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StaffAttendanceSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_id',
        'attendance_date',
        'attendance_policy_id',
        'punch_in_at',
        'punch_out_at',
        'punch_in_selfie_path',
        'punch_out_selfie_path',
        'punch_in_source',
        'punch_out_source',
        'is_auto_punch_out',
        'auto_punch_out_at',
        'auto_punch_out_reason',
        'timezone',
        'duration_minutes',
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'marked_by_user_id',
        'override_reason',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'punch_in_at' => 'datetime',
        'punch_out_at' => 'datetime',
        'is_auto_punch_out' => 'boolean',
        'auto_punch_out_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(StaffAttendancePolicy::class, 'attendance_policy_id');
    }

    public function punchEvents(): HasMany
    {
        return $this->hasMany(StaffAttendancePunchEvent::class, 'staff_attendance_session_id');
    }

    public function approvalLogs(): HasMany
    {
        return $this->hasMany(StaffAttendanceApprovalLog::class, 'staff_attendance_session_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by_user_id');
    }
}
