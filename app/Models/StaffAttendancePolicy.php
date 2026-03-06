<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StaffAttendancePolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'effective_from',
        'effective_to',
        'auto_punch_out_time',
        'require_selfie',
        'allow_manual_override',
        'grace_minutes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'require_selfie' => 'boolean',
        'allow_manual_override' => 'boolean',
    ];

    public function sessions(): HasMany
    {
        return $this->hasMany(StaffAttendanceSession::class, 'attendance_policy_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
