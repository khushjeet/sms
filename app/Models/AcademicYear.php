<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicYear extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'start_date', 'end_date', 'status', 'is_current', 'description'
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_current' => 'boolean',
    ];

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class);
    }

    public function examConfigurations(): HasMany
    {
        return $this->hasMany(AcademicYearExamConfig::class, 'academic_year_id');
    }

    public function examSessions(): HasMany
    {
        return $this->hasMany(ExamSession::class, 'academic_year_id');
    }

    public function feeStructures(): HasMany
    {
        return $this->hasMany(FeeStructure::class, 'academic_year_id');
    }

    public function feeInstallments(): HasMany
    {
        return $this->hasMany(FeeInstallment::class, 'academic_year_id');
    }

    public function optionalServices(): HasMany
    {
        return $this->hasMany(OptionalService::class, 'academic_year_id');
    }

    public function studentProfiles(): HasMany
    {
        return $this->hasMany(StudentProfile::class, 'academic_year_id');
    }

    public function attendanceMonthlySummaries(): HasMany
    {
        return $this->hasMany(AttendanceMonthlySummary::class, 'academic_year_id');
    }

    public function teacherMarks(): HasMany
    {
        return $this->hasMany(TeacherMark::class, 'academic_year_id');
    }

    public function compiledMarks(): HasMany
    {
        return $this->hasMany(CompiledMark::class, 'academic_year_id');
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
