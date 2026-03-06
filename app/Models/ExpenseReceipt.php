<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ExpenseReceipt extends Model
{
    use HasFactory;

    protected $fillable = [
        'expense_id',
        'file_name',
        'original_name',
        'mime_type',
        'extension',
        'size_bytes',
        'file_path',
        'uploaded_by',
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Expense receipt files are append-only. Upload a new file instead of updating.');
        });

        static::deleting(function () {
            throw new LogicException('Expense receipt files are append-only. Keep historical files for audit.');
        });
    }
}
