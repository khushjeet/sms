<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class Receipt extends Model
{
    use HasFactory;

    protected $fillable = [
        'enrollment_id',
        'receipt_number',
        'amount',
        'payment_method',
        'transaction_id',
        'paid_at',
        'received_by',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Receipts are append-only. Create a reversal instead of updating.');
        });

        static::deleting(function () {
            throw new LogicException('Receipts are append-only. Create a reversal instead of deleting.');
        });
    }
}

