<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant. Organization itself is NOT tenant-scoped (it is the tenant root);
 * only the platform owner queries across organizations, and an org user only
 * ever references their own via the user relationship.
 */
class Organization extends Model
{
    protected $fillable = [
        'name', 'slug', 'is_active', 'is_demo', 'expires_at', 'created_by_user_id', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_demo' => 'boolean',
            'expires_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    /** A demo org whose 1-hour window has elapsed. */
    public function isExpired(): bool
    {
        return $this->is_demo && $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function apiSources(): HasMany
    {
        return $this->hasMany(ApiSource::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
