<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialHold extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'outstanding_amount',
        'reason',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'outstanding_amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
