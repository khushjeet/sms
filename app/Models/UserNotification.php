<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'user_id',
        'role',
        'class_id',
        'section_id',
        'audience_type',
        'title',
        'message',
        'type',
        'priority',
        'entity_type',
        'entity_id',
        'action_target',
        'meta',
        'is_read',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'is_read' => 'boolean',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
