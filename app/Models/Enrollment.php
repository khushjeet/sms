<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Enrollment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'student_id',
        'academic_year_id',
        'class_id',
        'section_id',
        'roll_number',
        'enrollment_date',
        'status',
        'is_locked',
        'promoted_from_enrollment_id',
        'remarks',
    ];

    protected $casts = [
        'enrollment_date' => 'date',
        'is_locked' => 'boolean',
    ];

    // Relationships
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class); // SRS: Section may be null (nullable in database)
    }

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function promotedFromEnrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'promoted_from_enrollment_id');
    }

    public function promotedToEnrollment(): HasOne
    {
        return $this->hasOne(Enrollment::class, 'promoted_from_enrollment_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function feeAssignment(): HasOne
    {
        return $this->hasOne(FeeAssignment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function feeLedgerEntries(): HasMany
    {
        return $this->hasMany(StudentFeeLedger::class, 'enrollment_id');
    }

    public function studentFeeInstallments(): HasMany
    {
        return $this->hasMany(StudentFeeInstallment::class, 'enrollment_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class, 'enrollment_id');
    }

    public function studentResults(): HasMany
    {
        return $this->hasMany(StudentResult::class, 'enrollment_id');
    }

    public function admitCards(): HasMany
    {
        return $this->hasMany(AdmitCard::class, 'enrollment_id');
    }

    public function teacherMarks(): HasMany
    {
        return $this->hasMany(TeacherMark::class, 'enrollment_id');
    }

    public function compiledMarks(): HasMany
    {
        return $this->hasMany(CompiledMark::class, 'enrollment_id');
    }

    public function attendanceMonthlySummaries(): HasMany
    {
        return $this->hasMany(AttendanceMonthlySummary::class, 'enrollment_id');
    }

    public function transportAssignments(): HasMany
    {
        return $this->hasMany(StudentTransportAssignment::class, 'enrollment_id');
    }

    public function optionalServices(): BelongsToMany
    {
        return $this->belongsToMany(OptionalService::class, 'enrollment_optional_services')
            ->withTimestamps();
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active')->where('is_locked', false);
    }

    public function scopeCurrent($query)
    {
        return $query->whereHas('academicYear', function ($q) {
            $q->where('is_current', true);
        });
    }

    public function scopeByClass($query, $classId)
    {
        return $query->where('class_id', $classId);
    }

    // Helper Methods
    public function canReceiveAttendance(): bool
    {
        return $this->status === 'active' && !$this->is_locked;
    }

    public function canReceiveMarks(): bool
    {
        return $this->status === 'active' && !$this->is_locked;
    }

    public function getTotalFeesAttribute(): float
    {
        return (float) $this->feeLedgerEntries()
            ->where('transaction_type', 'debit')
            ->sum('amount');
    }

    public function getTotalPaidAttribute(): float
    {
        return (float) $this->feeLedgerEntries()
            ->where('transaction_type', 'credit')
            ->sum('amount');
    }

    public function getPendingDuesAttribute(): float
    {
        return $this->total_fees - $this->total_paid;
    }

    public function getAttendancePercentageAttribute(): float
    {
        $total = $this->attendances()->count();
        if ($total === 0) return 0;
        
        $present = $this->attendances()
            ->whereIn('status', ['present', 'half_day'])
            ->count();
        
        return round(($present / $total) * 100, 2);
    }

    /**
     * Get the full academic history chain (all enrollments linked through promotions)
     * Returns an array of enrollments from the earliest to the current
     */
    public function getAcademicHistoryChain(): array
    {
        $chain = [];
        $current = $this;

        // Go backwards to find the first enrollment
        while ($current && $current->promotedFromEnrollment) {
            array_unshift($chain, $current->promotedFromEnrollment);
            $current = $current->promotedFromEnrollment;
        }

        // Add current enrollment
        $chain[] = $this;

        // Go forwards to find future enrollments
        $current = $this;
        while ($current && $current->promotedToEnrollment) {
            $chain[] = $current->promotedToEnrollment;
            $current = $current->promotedToEnrollment;
        }

        return $chain;
    }

    /**
     * Check if this enrollment was promoted from another enrollment
     */
    public function isPromoted(): bool
    {
        return !is_null($this->promoted_from_enrollment_id) && $this->status === 'promoted';
    }

    /**
     * Check if this enrollment has been promoted to another enrollment
     */
    public function hasBeenPromoted(): bool
    {
        return $this->promotedToEnrollment()->exists();
    }

    /**
     * Get the original enrollment (first in the chain)
     */
    public function getOriginalEnrollment(): ?self
    {
        $current = $this;
        while ($current && $current->promotedFromEnrollment) {
            $current = $current->promotedFromEnrollment;
        }
        return $current;
    }
}
