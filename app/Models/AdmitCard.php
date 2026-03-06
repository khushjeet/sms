<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AdmitCard extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_session_id',
        'admit_schedule_snapshot_id',
        'enrollment_id',
        'student_id',
        'roll_number',
        'seat_number',
        'center_name',
        'status',
        'version',
        'is_superseded',
        'remarks',
        'generated_by',
        'generated_at',
        'published_by',
        'published_at',
        'verification_uuid',
        'verification_hash',
        'verification_status',
    ];

    protected $casts = [
        'is_superseded' => 'boolean',
        'generated_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function examSession(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class);
    }

    public function scheduleSnapshot(): BelongsTo
    {
        return $this->belongsTo(AdmitScheduleSnapshot::class, 'admit_schedule_snapshot_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function visibilityControls(): HasMany
    {
        return $this->hasMany(AdmitVisibilityControl::class);
    }

    public function latestVisibility(): HasOne
    {
        return $this->hasOne(AdmitVisibilityControl::class)->latestOfMany('visibility_version');
    }
}
