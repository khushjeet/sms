<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class Expense extends Model
{
    use HasFactory;

    protected $fillable = [
        'expense_number',
        'expense_date',
        'category',
        'description',
        'vendor_name',
        'amount',
        'payment_method',
        'payment_account_code',
        'expense_account_code',
        'reference_number',
        'created_by',
        'is_reversal',
        'reversal_of_expense_id',
        'reversed_by',
        'reversed_at',
        'reversal_reason',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
        'is_reversal' => 'boolean',
        'reversed_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_expense_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_expense_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(ExpenseReceipt::class)->latest('id');
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Expenses are append-only. Create a reversal entry instead of updating.');
        });

        static::deleting(function () {
            throw new LogicException('Expenses are append-only. Create a reversal entry instead of deleting.');
        });
    }
}
