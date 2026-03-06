<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportFeeCycle extends Model
{
    use HasFactory;

    protected $fillable = [
        'assignment_id',
        'month',
        'year',
        'amount',
        'generated_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'generated_at' => 'datetime',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(StudentTransportAssignment::class, 'assignment_id');
    }
}
