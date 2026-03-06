<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompiledMark extends Model
{
    use HasFactory;

    protected $fillable = [
        'enrollment_id',
        'subject_id',
        'section_id',
        'academic_year_id',
        'exam_configuration_id',
        'exam_session_id',
        'marked_on',
        'marks_obtained',
        'max_marks',
        'remarks',
        'is_finalized',
        'compiled_by',
        'compiled_at',
        'finalized_by',
        'finalized_at',
    ];

    protected $casts = [
        'marked_on' => 'date',
        'marks_obtained' => 'decimal:2',
        'max_marks' => 'decimal:2',
        'is_finalized' => 'boolean',
        'compiled_at' => 'datetime',
        'finalized_at' => 'datetime',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function examConfiguration(): BelongsTo
    {
        return $this->belongsTo(AcademicYearExamConfig::class, 'exam_configuration_id');
    }

    public function examSession(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class);
    }

    public function compiledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'compiled_by');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }
}
