<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherMark extends Model
{
    use HasFactory;

    protected $fillable = [
        'enrollment_id',
        'subject_id',
        'section_id',
        'academic_year_id',
        'exam_configuration_id',
        'teacher_id',
        'marked_on',
        'marks_obtained',
        'max_marks',
        'remarks',
    ];

    protected $casts = [
        'marked_on' => 'date',
        'marks_obtained' => 'decimal:2',
        'max_marks' => 'decimal:2',
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

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}
