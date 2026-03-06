<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffAttendanceApprovalLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_attendance_session_id',
        'from_status',
        'to_status',
        'action',
        'acted_by',
        'acted_at',
        'remarks',
    ];

    protected $casts = [
        'acted_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(StaffAttendanceSession::class, 'staff_attendance_session_id');
    }

    public function actedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }
}
