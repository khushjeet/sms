<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceMonthlySummary extends Model
{
    use HasFactory;

    protected $fillable = [
        'enrollment_id',
        'academic_year_id',
        'month',
        'present_count',
        'absent_count',
        'leave_count',
        'half_day_count',
        'total_count',
        'attendance_percentage',
    ];

    protected $casts = [
        'attendance_percentage' => 'decimal:2',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
