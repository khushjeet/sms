<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'subject_code',
        'short_name',
        'type',
        'category',
        'description',
        'status',
        'is_active',
        'credits',
        'effective_from',
        'effective_to',
        'board_code',
        'lms_code',
        'erp_code',
        'archived_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'archived_at' => 'datetime',
    ];

    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(ClassModel::class, 'class_subjects', 'subject_id', 'class_id')
            ->withPivot('academic_year_id', 'academic_year_exam_config_id', 'max_marks', 'pass_marks', 'is_mandatory')
            ->withTimestamps();
    }

    public function teacherMarks(): HasMany
    {
        return $this->hasMany(TeacherMark::class);
    }

    public function compiledMarks(): HasMany
    {
        return $this->hasMany(CompiledMark::class);
    }

    public function resultMarkSnapshots(): HasMany
    {
        return $this->hasMany(ResultMarkSnapshot::class);
    }

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->where('is_active', true)
                ->orWhere('status', 'active');
        });
    }
}
