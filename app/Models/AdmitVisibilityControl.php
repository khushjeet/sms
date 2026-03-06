<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdmitVisibilityControl extends Model
{
    use HasFactory;

    protected $fillable = [
        'admit_card_id',
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

    public function admitCard(): BelongsTo
    {
        return $this->belongsTo(AdmitCard::class);
    }

    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }

    public function unblocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unblocked_by');
    }
}
