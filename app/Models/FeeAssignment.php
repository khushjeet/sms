<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'enrollment_id',
        'base_fee',
        'optional_services_fee',
        'discount',
        'total_fee',
        'discount_reason',
        'discount_approved_by',
        'discount_approved_at',
    ];

    protected $casts = [
        'base_fee' => 'decimal:2',
        'optional_services_fee' => 'decimal:2',
        'discount' => 'decimal:2',
        'total_fee' => 'decimal:2',
        'discount_approved_at' => 'datetime',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function discountApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discount_approved_by');
    }
}
