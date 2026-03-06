<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollBatchItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_batch_id',
        'staff_id',
        'staff_salary_structure_id',
        'days_in_month',
        'payable_days',
        'leave_days',
        'absent_days',
        'gross_pay',
        'total_deductions',
        'net_pay',
        'snapshot',
    ];

    protected $casts = [
        'payable_days' => 'decimal:2',
        'leave_days' => 'decimal:2',
        'absent_days' => 'decimal:2',
        'gross_pay' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_pay' => 'decimal:2',
        'snapshot' => 'array',
    ];

    public function payrollBatch(): BelongsTo
    {
        return $this->belongsTo(PayrollBatch::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(PayrollItemAdjustment::class);
    }
}
