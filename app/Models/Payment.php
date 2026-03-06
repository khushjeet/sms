<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class Payment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'enrollment_id',
        'receipt_number',
        'amount',
        'payment_date',
        'payment_method',
        'transaction_id',
        'remarks',
        'received_by',
        'is_refunded',
        'reversal_of_payment_id',
        'refunded_by',
        'refunded_at',
        'refund_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
        'is_refunded' => 'boolean',
        'refunded_at' => 'datetime',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_payment_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_payment_id');
    }

    public function refundedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Payments/receipts are append-only. Create a reversal/refund record instead of updating.');
        });

        static::deleting(function () {
            throw new LogicException('Payments/receipts are append-only. Create a reversal/refund record instead of deleting.');
        });
    }
}
