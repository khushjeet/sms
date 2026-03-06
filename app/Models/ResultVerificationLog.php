<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResultVerificationLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'student_result_id',
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
}

