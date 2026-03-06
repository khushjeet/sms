<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdmitVerificationLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'admit_card_id',
        'verification_uuid',
        'status',
        'message',
        'ip_address',
        'user_agent',
        'verified_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
    ];

    public function admitCard(): BelongsTo
    {
        return $this->belongsTo(AdmitCard::class);
    }
}
