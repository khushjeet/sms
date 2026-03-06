<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'year',
        'month',
        'period_start',
        'period_end',
        'status',
        'is_locked',
        'generated_at',
        'generated_by',
        'finalized_at',
        'finalized_by',
        'paid_at',
        'paid_by',
        'journal_entry_id',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'is_locked' => 'boolean',
        'generated_at' => 'datetime',
        'finalized_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PayrollBatchItem::class);
    }
}
