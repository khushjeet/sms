<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentFeeInstallment extends Model
{
    use HasFactory;

    protected $table = 'enrollment_fee_installments';

    protected $fillable = [
        'enrollment_id',
        'fee_installment_id',
        'amount',
        'assigned_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    protected $appends = [
        'student_id',
        'academic_year_id',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function feeInstallment(): BelongsTo
    {
        return $this->belongsTo(FeeInstallment::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function getStudentIdAttribute(): ?int
    {
        $enrollment = $this->relationLoaded('enrollment')
            ? $this->getRelation('enrollment')
            : $this->enrollment;

        return $enrollment?->student_id;
    }

    public function getAcademicYearIdAttribute(): ?int
    {
        $enrollment = $this->relationLoaded('enrollment')
            ? $this->getRelation('enrollment')
            : $this->enrollment;

        return $enrollment?->academic_year_id;
    }
}
