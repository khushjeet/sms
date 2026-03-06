<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResultMarkSnapshot extends Model
{
    use HasFactory;

    protected $table = 'result_marks_snapshots';

    public $timestamps = false;

    protected $fillable = [
        'exam_session_id',
        'student_result_id',
        'enrollment_id',
        'student_id',
        'subject_id',
        'obtained_marks',
        'max_marks',
        'grade',
        'teacher_id',
        'snapshot_version',
        'created_at',
    ];

    protected $casts = [
        'obtained_marks' => 'decimal:2',
        'max_marks' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function studentResult(): BelongsTo
    {
        return $this->belongsTo(StudentResult::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}
