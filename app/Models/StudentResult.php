<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StudentResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_session_id',
        'enrollment_id',
        'student_id',
        'total_marks',
        'total_max_marks',
        'percentage',
        'grade',
        'rank',
        'result_status',
        'remarks',
        'version',
        'is_superseded',
        'published_by',
        'published_at',
        'verification_uuid',
        'verification_hash',
        'verification_status',
    ];

    protected $casts = [
        'total_marks' => 'decimal:2',
        'total_max_marks' => 'decimal:2',
        'percentage' => 'decimal:2',
        'is_superseded' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function examSession(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(ResultMarkSnapshot::class);
    }

    public function visibilityControls(): HasMany
    {
        return $this->hasMany(ResultVisibilityControl::class);
    }

    public function latestVisibility(): HasOne
    {
        return $this->hasOne(ResultVisibilityControl::class)->latestOfMany('visibility_version');
    }
}
