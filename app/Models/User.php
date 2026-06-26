<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToOrganization, HasApiTokens, HasFactory, Notifiable;

    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_ANALYST = 'analyst';
    public const ROLE_VIEWER = 'viewer';

    public const ROLES = [self::ROLE_SUPER_ADMIN, self::ROLE_ADMIN, self::ROLE_ANALYST, self::ROLE_VIEWER];

    /** Privilege levels — a user can only administer accounts of a strictly lower level. */
    public const LEVELS = [
        self::ROLE_VIEWER => 1,
        self::ROLE_ANALYST => 2,
        self::ROLE_ADMIN => 3,
        self::ROLE_SUPER_ADMIN => 4,
    ];

    protected $fillable = [
        'organization_id',
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'mfa_secret',
        'mfa_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'mfa_enabled' => 'boolean',
            'mfa_required' => 'boolean',
            'mfa_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'locked_until' => 'datetime',
            'ip_flagged' => 'boolean',
            'must_change_password' => 'boolean',
            'failed_login_attempts' => 'integer',
            'preferences' => 'array',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Role helpers                                                        */
    /* ------------------------------------------------------------------ */

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    /** "Admin or above" — super admins inherit every admin capability. */
    public function isAdmin(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_SUPER_ADMIN], true);
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /** Can this user modify data (sources/dashboards), vs. view only? */
    public function canManage(): bool
    {
        return $this->hasRole(self::ROLE_SUPER_ADMIN, self::ROLE_ADMIN, self::ROLE_ANALYST);
    }

    public function level(): int
    {
        return self::LEVELS[$this->role] ?? 0;
    }

    /** Whether this user can administer the target (must be strictly higher rank). */
    public function outranks(self $target): bool
    {
        return $this->level() > $target->level();
    }

    /** Whether this user may grant the given role (only roles below their own level). */
    public function canAssignRole(string $role): bool
    {
        return (self::LEVELS[$role] ?? 99) < $this->level();
    }

    public function isOnline(): bool
    {
        $window = (int) config('security.online_window_minutes', 5);

        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subMinutes($window));
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /* ------------------------------------------------------------------ */
    /* Relationships                                                       */
    /* ------------------------------------------------------------------ */

    public function apiSources(): HasMany
    {
        return $this->hasMany(ApiSource::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function dashboards(): HasMany
    {
        return $this->hasMany(Dashboard::class);
    }

    /** Dashboards an admin has assigned to this user (read-only access). */
    public function assignedDashboards(): BelongsToMany
    {
        return $this->belongsToMany(Dashboard::class, 'dashboard_user')
            ->withPivot(['assigned_by_user_id', 'created_at'])
            ->withTimestamps();
    }

    public function loginEvents(): HasMany
    {
        return $this->hasMany(LoginEvent::class);
    }

    public function knownIps(): HasMany
    {
        return $this->hasMany(KnownIp::class);
    }
}
