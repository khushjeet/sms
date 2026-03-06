<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransportStop extends Model
{
    use HasFactory;

    protected $fillable = [
        'route_id',
        'stop_name',
        'fee_amount',
        'distance_km',
        'pickup_time',
        'drop_time',
        'stop_order',
        'active',
    ];

    protected $casts = [
        'pickup_time' => 'string',
        'drop_time' => 'string',
        'distance_km' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'active' => 'boolean',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class, 'route_id');
    }

    public function studentTransports(): HasMany
    {
        return $this->hasMany(StudentTransportAssignment::class, 'stop_id');
    }
}
