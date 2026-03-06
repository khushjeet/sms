<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffAttendancePunchEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_attendance_session_id',
        'staff_id',
        'punch_type',
        'punched_at',
        'selfie_path',
        'selfie_sha256',
        'selfie_metadata',
        'latitude',
        'longitude',
        'location_accuracy_meters',
        'ip_address',
        'device_id',
        'user_agent',
        'source',
        'is_system_generated',
        'captured_by_user_id',
        'note',
    ];

    protected $casts = [
        'punched_at' => 'datetime',
        'selfie_metadata' => 'array',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'is_system_generated' => 'boolean',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(StaffAttendanceSession::class, 'staff_attendance_session_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }
}
