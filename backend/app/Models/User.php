<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Notifications\MarginResetPasswordNotification;

#[Fillable([
    'name',
    'email',
    'password',
    'university_id',
    'department_id',
    'role',
    'account_status',
    'avatar_path',
    'mfa_secret',
    'mfa_recovery_codes',
])]
#[Hidden(['password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class, 'student_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_id');
    }

    public function isPlatformAdmin(): bool
    {
        return $this->role === 'platform_admin';
    }

    public function isUniversityAdmin(): bool
    {
        return $this->role === 'university_admin';
    }

    public function isDepartmentAdmin(): bool
    {
        return $this->role === 'department_admin';
    }

    public function managesUniversity(int $universityId): bool
    {
        return $this->isPlatformAdmin()
            || ($this->isUniversityAdmin() && $this->university_id === $universityId);
    }

    public function createdAssignments(): HasMany
    {
        return $this->hasMany(Assignment::class, 'created_by');
    }

    public function gradedSubmissions(): HasMany
    {
        return $this->hasMany(Grade::class, 'graded_by');
    }

    public function publishedGrades(): HasMany
    {
        return $this->hasMany(Grade::class, 'published_by');
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'mfa_secret' => 'encrypted',
            'mfa_recovery_codes' => 'encrypted:array',
            'mfa_confirmed_at' => 'datetime',
        ];
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new MarginResetPasswordNotification($token));
    }

    /**
     * Get the department this user belongs to.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'student_id');
    }

    public function staffAssignments(): HasMany
    {
        return $this->hasMany(CourseStaff::class);
    }

    /**
     * Get the university this user belongs to.
     */
    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }
}
