<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    private static ?bool $rbacReadyCache = null;

    private ?array $activeRoleNamesCache = null;

    private ?array $permissionCodesCache = null;

    private array $moduleAccessCache = [];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'password',
        'role',
        'first_name',
        'last_name',
        'phone',
        'avatar',
        'status',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = [
        'full_name',
        'avatar_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (User $user): void {
            $user->syncLegacyRoleIntoRbac();
        });

        static::updated(function (User $user): void {
            if ($user->wasChanged('role')) {
                $user->syncLegacyRoleIntoRbac();
            }
        });
    }

    // Relationships
    public function student()
    {
        return $this->hasOne(Student::class);
    }

    public function parent()
    {
        return $this->hasOne(ParentModel::class);
    }

    public function staff()
    {
        return $this->hasOne(Staff::class);
    }

    public function sentNotifications()
    {
        return $this->hasMany(Notification::class, 'sent_by');
    }

    public function readNotifications()
    {
        return $this->belongsToMany(Notification::class, 'notification_reads')
            ->withPivot('read_at')
            ->withTimestamps();
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot(['assigned_at', 'assigned_by', 'expires_at'])
            ->withTimestamps();
    }

    public function classTeacherSections(): HasMany
    {
        return $this->hasMany(Section::class, 'class_teacher_id');
    }

    public function markedAttendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'marked_by');
    }

    public function receivedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'received_by');
    }

    public function refundedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'refunded_by');
    }

    public function receivedReceipts(): HasMany
    {
        return $this->hasMany(Receipt::class, 'received_by');
    }

    public function createdFinancialHolds(): HasMany
    {
        return $this->hasMany(FinancialHold::class, 'created_by');
    }

    public function assignedFeeInstallments(): HasMany
    {
        return $this->hasMany(StudentFeeInstallment::class, 'assigned_by');
    }

    public function postedFeeLedgerEntries(): HasMany
    {
        return $this->hasMany(StudentFeeLedger::class, 'posted_by');
    }

    public function assignedTransports(): HasMany
    {
        return $this->hasMany(StudentTransportAssignment::class, 'assigned_by');
    }

    public function uploadedTeacherDocuments(): HasMany
    {
        return $this->hasMany(TeacherDocument::class, 'uploaded_by');
    }

    public function uploadedExpenseReceipts(): HasMany
    {
        return $this->hasMany(ExpenseReceipt::class, 'uploaded_by');
    }

    public function createdExpenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'created_by');
    }

    public function reversedExpenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'reversed_by');
    }

    public function createdExamConfigurations(): HasMany
    {
        return $this->hasMany(AcademicYearExamConfig::class, 'created_by');
    }

    public function createdExamSessions(): HasMany
    {
        return $this->hasMany(ExamSession::class, 'created_by');
    }

    public function teacherMarks(): HasMany
    {
        return $this->hasMany(TeacherMark::class, 'teacher_id');
    }

    public function compiledMarks(): HasMany
    {
        return $this->hasMany(CompiledMark::class, 'compiled_by');
    }

    public function finalizedCompiledMarks(): HasMany
    {
        return $this->hasMany(CompiledMark::class, 'finalized_by');
    }

    public function publishedStudentResults(): HasMany
    {
        return $this->hasMany(StudentResult::class, 'published_by');
    }

    public function reviewedAttendanceSessions(): HasMany
    {
        return $this->hasMany(StaffAttendanceSession::class, 'reviewed_by');
    }

    public function markedAttendanceSessions(): HasMany
    {
        return $this->hasMany(StaffAttendanceSession::class, 'marked_by_user_id');
    }

    public function capturedPunchEvents(): HasMany
    {
        return $this->hasMany(StaffAttendancePunchEvent::class, 'captured_by_user_id');
    }

    public function actedAttendanceApprovalLogs(): HasMany
    {
        return $this->hasMany(StaffAttendanceApprovalLog::class, 'acted_by');
    }

    // Role Checks
    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    public function isSchoolAdmin(): bool
    {
        return $this->hasRole('school_admin');
    }

    public function isAccountant(): bool
    {
        return $this->hasRole('accountant');
    }

    public function isTeacher(): bool
    {
        return $this->hasRole('teacher');
    }

    public function isParent(): bool
    {
        return $this->hasRole('parent');
    }

    public function isStudent(): bool
    {
        return $this->hasRole('student');
    }

    public function hasRole(string|array $roles): bool
    {
        $requiredRoles = is_array($roles) ? $roles : [$roles];

        if ($this->isRbacReady()) {
            $activeRoleNames = $this->getCachedActiveRoleNames();
            if (!empty($activeRoleNames)) {
                return !empty(array_intersect($requiredRoles, $activeRoleNames));
            }
        }

        return in_array($this->role, $requiredRoles, true);
    }

    public function assignRole(string $roleName, ?int $assignedBy = null, ?\DateTimeInterface $expiresAt = null): void
    {
        if (!$this->isRbacReady()) {
            return;
        }

        DB::transaction(function () use ($roleName, $assignedBy, $expiresAt): void {
            $role = Role::query()->firstOrCreate(
                ['name' => $roleName],
                [
                    'description' => ucfirst(str_replace('_', ' ', $roleName)),
                    'is_system_role' => true,
                ]
            );

            $this->roles()->syncWithoutDetaching([
                $role->id => [
                    'assigned_at' => now(),
                    'assigned_by' => $assignedBy,
                    'expires_at' => $expiresAt,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        });

        $this->flushAuthorizationCache();
    }

    public function syncLegacyRoleIntoRbac(): void
    {
        if (!$this->isRbacReady() || !$this->role) {
            return;
        }

        $this->assignRole($this->role);
    }

    public function hasPermission(string $code): bool
    {
        if ($this->hasRole('super_admin')) {
            return true;
        }

        if (!$this->isRbacReady()) {
            return false;
        }

        return in_array($code, $this->getCachedPermissionCodes(), true);
    }

    public function getRoleNames(): array
    {
        if ($this->isRbacReady()) {
            $roles = $this->getCachedActiveRoleNames();
            if (!empty($roles)) {
                return $roles;
            }
        }

        return $this->role ? [$this->role] : [];
    }

    public function getPrimaryRole(): ?string
    {
        return $this->getRoleNames()[0] ?? null;
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByRole($query, $role)
    {
        if ($this->isRbacReady()) {
            return $query->where(function (Builder $q) use ($role): void {
                $q->where('role', $role)
                    ->orWhereHas('roles', function (Builder $roleQuery) use ($role): void {
                        $roleQuery->where('name', $role)
                            ->where(function (Builder $pivotQuery): void {
                                $pivotQuery->whereNull('user_roles.expires_at')
                                    ->orWhere('user_roles.expires_at', '>', now());
                            });
                    });
            });
        }

        return $query->where('role', $role);
    }

    // Helper Methods
    public function getFullNameAttribute(): string
    {
        return $this->first_name . ' ' . $this->last_name;
    }

    public function getAvatarUrlAttribute(): ?string
    {
        $avatar = trim((string) ($this->avatar ?? ''));

        if ($avatar === '') {
            return null;
        }

        if (str_starts_with($avatar, 'http://') || str_starts_with($avatar, 'https://')) {
            return $avatar;
        }

        return Storage::disk('public')->url(ltrim($avatar, '/'));
    }

    public function canAccessModule(string $module): bool
    {
        if (array_key_exists($module, $this->moduleAccessCache)) {
            return $this->moduleAccessCache[$module];
        }

        if ($this->isSuperAdmin()) {
            return $this->moduleAccessCache[$module] = true;
        }

        $expectedCodes = [
            "{$module}.view",
            "{$module}.manage",
        ];

        foreach ($expectedCodes as $code) {
            if ($this->hasPermission($code)) {
                return $this->moduleAccessCache[$module] = true;
            }
        }

        $legacyPermissions = [
            'school_admin' => ['students', 'staff', 'academic', 'finance', 'reports'],
            'hr' => ['staff', 'attendance', 'reports'],
            'accountant' => ['finance', 'reports'],
            'teacher' => ['academic', 'attendance', 'marks'],
            'parent' => ['view_student_info'],
            'student' => ['view_own_info'],
        ];
        $rolePermissions = $legacyPermissions[$this->role] ?? [];

        return $this->moduleAccessCache[$module] = in_array($module, $rolePermissions, true);
    }

    private function isRbacReady(): bool
    {
        if (self::$rbacReadyCache !== null) {
            return self::$rbacReadyCache;
        }

        return self::$rbacReadyCache = Schema::hasTable('roles')
            && Schema::hasTable('permissions')
            && Schema::hasTable('role_permissions')
            && Schema::hasTable('user_roles');
    }

    private function getCachedActiveRoleNames(): array
    {
        if ($this->activeRoleNamesCache !== null) {
            return $this->activeRoleNamesCache;
        }

        return $this->activeRoleNamesCache = $this->roles()
            ->where(function (Builder $query): void {
                $query->whereNull('user_roles.expires_at')
                    ->orWhere('user_roles.expires_at', '>', now());
            })
            ->pluck('name')
            ->all();
    }

    private function getCachedPermissionCodes(): array
    {
        if ($this->permissionCodesCache !== null) {
            return $this->permissionCodesCache;
        }

        return $this->permissionCodesCache = Permission::query()
            ->whereHas('roles.users', function (Builder $query): void {
                $query->where('users.id', $this->id)
                    ->where(function (Builder $sub): void {
                        $sub->whereNull('user_roles.expires_at')
                            ->orWhere('user_roles.expires_at', '>', now());
                    });
            })
            ->pluck('code')
            ->all();
    }

    private function flushAuthorizationCache(): void
    {
        $this->activeRoleNamesCache = null;
        $this->permissionCodesCache = null;
        $this->moduleAccessCache = [];
    }
}
