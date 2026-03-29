<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Student extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'admission_number',
        'admission_date',
        'date_of_birth',
        'gender',
        'blood_group',
        'address',
        'city',
        'state',
        'pincode',
        'nationality',
        'religion',
        'category',
        'aadhar_number',
        'medical_info',
        'avatar_url',
        'status',
        'remarks',
    ];

    protected $casts = [
        'admission_date' => 'date',
        'date_of_birth' => 'date',
        'medical_info' => 'array',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parents(): BelongsToMany
    {
        return $this->belongsToMany(ParentModel::class, 'student_parent', 'student_id', 'parent_id')
            ->withPivot('relation', 'is_primary')
            ->withTimestamps();
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    public function currentEnrollment()
    {
        return $this->hasOne(Enrollment::class)
            ->whereHas('academicYear', function ($query) {
                $query->where('is_current', true);
            })
            ->where('status', 'active');
    }

    public function latestEnrollment(): HasOne
    {
        return $this->hasOne(Enrollment::class)->orderByDesc('id');
    }

    public function bookIssues(): HasMany
    {
        return $this->hasMany(BookIssue::class);
    }

    public function financialHolds(): HasMany
    {
        return $this->hasMany(FinancialHold::class);
    }

    public function studentResults(): HasMany
    {
        return $this->hasMany(StudentResult::class, 'student_id');
    }

    public function admitCards(): HasMany
    {
        return $this->hasMany(AdmitCard::class, 'student_id');
    }

    public function eventParticipants(): HasMany
    {
        return $this->hasMany(SchoolEventParticipant::class, 'student_id');
    }

    public function transport()
    {
        // Legacy relationship removed: transport is anchored to enrollment via StudentTransportAssignment.
        return $this->hasOne(StudentTransportAssignment::class)
            ->whereHas('enrollment.academicYear', function ($query) {
                $query->where('is_current', true);
            })
            ->where('status', 'active');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeAlumni($query)
    {
        return $query->where('status', 'alumni');
    }

    // Helper Methods
    public function getFullNameAttribute(): string
    {
        return $this->user->first_name . ' ' . $this->user->last_name;
    }

    public function getAgeAttribute(): int
    {
        return $this->date_of_birth->age;
    }

    public function hasFinancialHold(): bool
    {
        return $this->financialHolds()->where('is_active', true)->exists();
    }
}
