<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchoolEventParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_event_id',
        'student_id',
        'enrollment_id',
        'rank',
        'achievement_title',
        'remarks',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(SchoolEvent::class, 'school_event_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
