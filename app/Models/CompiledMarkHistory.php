<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompiledMarkHistory extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'compiled_mark_id',
        'version_no',
        'action',
        'enrollment_id',
        'subject_id',
        'section_id',
        'academic_year_id',
        'exam_configuration_id',
        'exam_session_id',
        'marked_on',
        'marks_obtained',
        'max_marks',
        'remarks',
        'is_finalized',
        'changed_by',
        'changed_at',
        'metadata',
    ];

    protected $casts = [
        'marked_on' => 'date',
        'marks_obtained' => 'decimal:2',
        'max_marks' => 'decimal:2',
        'is_finalized' => 'boolean',
        'changed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function compiledMark(): BelongsTo
    {
        return $this->belongsTo(CompiledMark::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
