<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'class_id',
        'academic_year_id',
        'name',
        'capacity',
        'class_teacher_id',
        'room_number',
        'status',
    ];

    // Relationships
    public function class(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function classTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'class_teacher_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function teacherMarks(): HasMany
    {
        return $this->hasMany(TeacherMark::class);
    }

    public function compiledMarks(): HasMany
    {
        return $this->hasMany(CompiledMark::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByClass($query, $classId)
    {
        return $query->where('class_id', $classId);
    }

    public function scopeByAcademicYear($query, $academicYearId)
    {
        return $query->where('academic_year_id', $academicYearId);
    }

    // Helper Methods
    public function getCurrentEnrollmentsCountAttribute(): int
    {
        return $this->enrollments()
            ->where('status', 'active')
            ->count();
    }

    public function getAvailableSeatsAttribute(): int
    {
        return max(0, $this->capacity - $this->current_enrollments_count);
    }

    public function isFull(): bool
    {
        return $this->current_enrollments_count >= $this->capacity;
    }
}
