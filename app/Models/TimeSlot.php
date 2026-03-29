<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TimeSlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'start_time',
        'end_time',
        'is_break',
        'slot_order',
    ];

    protected $casts = [
        'is_break' => 'boolean',
    ];

    public function timetables(): HasMany
    {
        return $this->hasMany(Timetable::class);
    }
}
