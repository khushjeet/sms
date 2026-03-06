<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdmitScheduleSnapshot extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'exam_session_id',
        'snapshot_version',
        'schedule_snapshot',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'schedule_snapshot' => 'array',
        'created_at' => 'datetime',
    ];

    public function examSession(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function admitCards(): HasMany
    {
        return $this->hasMany(AdmitCard::class, 'admit_schedule_snapshot_id');
    }
}
