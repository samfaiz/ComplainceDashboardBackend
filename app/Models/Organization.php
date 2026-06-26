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
        'name', 'slug', 'is_active', 'created_by_user_id', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
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
