<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TeacherDocument extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'staff_id',
        'document_type',
        'file_name',
        'original_name',
        'mime_type',
        'extension',
        'size_bytes',
        'sha256',
        'file_path',
        'uploaded_by',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

