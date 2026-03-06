<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransportRoute extends Model
{
    use HasFactory;

    protected $fillable = [
        'route_name',
        'route_number',
        'vehicle_number',
        'driver_name',
        'description',
        'fee_amount',
        'status',
        'active',
    ];

    protected $casts = [
        'fee_amount' => 'decimal:2',
        'active' => 'boolean',
    ];

    public function stops(): HasMany
    {
        return $this->hasMany(TransportStop::class, 'route_id');
    }

    public function studentTransports(): HasMany
    {
        return $this->hasMany(StudentTransportAssignment::class, 'route_id');
    }
}
