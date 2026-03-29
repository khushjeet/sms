<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'academic_year_id',
        'class_id',
        'exam_configuration_id',
        'name',
        'class_name_snapshot',
        'exam_name_snapshot',
        'academic_year_label_snapshot',
        'school_snapshot',
        'identity_locked_at',
        'status',
        'published_at',
        'locked_at',
        'created_by',
    ];

    protected $casts = [
        'school_snapshot' => 'array',
        'identity_locked_at' => 'datetime',
        'published_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function examConfiguration(): BelongsTo
    {
        return $this->belongsTo(AcademicYearExamConfig::class, 'exam_configuration_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function studentResults(): HasMany
    {
        return $this->hasMany(StudentResult::class);
    }

    public function compiledMarks(): HasMany
    {
        return $this->hasMany(CompiledMark::class, 'exam_session_id');
    }

    public function admitCards(): HasMany
    {
        return $this->hasMany(AdmitCard::class);
    }

    public function admitScheduleSnapshots(): HasMany
    {
        return $this->hasMany(AdmitScheduleSnapshot::class);
    }
}
