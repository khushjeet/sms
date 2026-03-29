<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'academic_year_id',
        'title',
        'event_date',
        'venue',
        'description',
        'status',
        'certificate_prefix',
    ];

    protected $casts = [
        'event_date' => 'date',
    ];

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(SchoolEventParticipant::class);
    }
}
