<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollItemAdjustment extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_batch_item_id',
        'adjustment_type',
        'amount',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function payrollItem(): BelongsTo
    {
        return $this->belongsTo(PayrollBatchItem::class, 'payroll_batch_item_id');
    }
}
