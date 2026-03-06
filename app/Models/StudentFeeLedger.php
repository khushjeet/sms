<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StudentFeeLedger extends Model
{
    use HasFactory;

    protected $table = 'student_fee_ledger';

    protected $fillable = [
        'enrollment_id',
        'financial_year_id',
        'financial_period_id',
        'transaction_type',
        'reference_type',
        'reference_id',
        'amount',
        'posted_by',
        'posted_at',
        'narration',
        'is_reversal',
        'reversal_of',
        'journal_entry_id',
        'journal_line_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'posted_at' => 'datetime',
        'is_reversal' => 'boolean',
    ];

    protected $appends = [
        'student_id',
        'academic_year_id',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
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

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Student fee ledger is append-only. Create a reversal entry instead of updating.');
        });

        static::deleting(function () {
            throw new LogicException('Student fee ledger is append-only. Create a reversal entry instead of deleting.');
        });
    }
}
