<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'user_id',
        'avatar_url',
        'academic_year_id',
        'class_id',
        'roll_number',
        'caste',
        'father_name',
        'father_email',
        'father_mobile',
        'father_mobile_number',
        'father_occupation',
        'mother_name',
        'mother_email',
        'mother_mobile',
        'mother_mobile_number',
        'mother_occupation',
        'bank_account_number',
        'bank_account_holder',
        'ifsc_code',
        'relation_with_account_holder',
        'permanent_address',
        'current_address',
        'principal_signature_path',
        'director_signature_path',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }
}
