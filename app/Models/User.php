<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_ANALYST = 'analyst';
    public const ROLE_VIEWER = 'viewer';

    public const ROLES = [self::ROLE_ADMIN, self::ROLE_ANALYST, self::ROLE_VIEWER];

    protected $fillable = [
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

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /** Can this user modify data (sources/dashboards), vs. view only? */
    public function canManage(): bool
    {
        return $this->hasRole(self::ROLE_ADMIN, self::ROLE_ANALYST);
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

    public function loginEvents(): HasMany
    {
        return $this->hasMany(LoginEvent::class);
    }

    public function knownIps(): HasMany
    {
        return $this->hasMany(KnownIp::class);
    }
}
