<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledMessage extends Model
{
    protected $fillable = [
        'language',
        'channel',
        'audience',
        'subject',
        'message',
        'student_ids',
        'scheduled_for',
        'status',
        'batch_id',
        'sent_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'student_ids' => 'array',
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
