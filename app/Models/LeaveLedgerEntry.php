<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveLedgerEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_id',
        'leave_type_id',
        'entry_type',
        'quantity',
        'entry_date',
        'reference_type',
        'reference_id',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'quantity' => 'decimal:2',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
