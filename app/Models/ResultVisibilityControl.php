<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResultVisibilityControl extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_result_id',
        'visibility_status',
        'blocked_reason',
        'blocked_by',
        'blocked_at',
        'unblocked_by',
        'unblocked_at',
        'visibility_version',
    ];

    protected $casts = [
        'blocked_at' => 'datetime',
        'unblocked_at' => 'datetime',
    ];

    public function studentResult(): BelongsTo
    {
        return $this->belongsTo(StudentResult::class);
    }
}

