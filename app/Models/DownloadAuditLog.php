<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DownloadAuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'module',
        'report_key',
        'report_label',
        'format',
        'status',
        'file_name',
        'file_checksum',
        'row_count',
        'filters',
        'context',
        'ip_address',
        'user_agent',
        'downloaded_at',
    ];

    protected $casts = [
        'filters' => 'array',
        'context' => 'array',
        'downloaded_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
