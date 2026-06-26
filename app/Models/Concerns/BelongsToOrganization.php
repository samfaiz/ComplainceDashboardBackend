<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Makes a model tenant-scoped: every query is filtered to the active
 * organization (see OrganizationScope) and new records are stamped with the
 * active organization_id automatically. Apply to every model that holds
 * tenant data.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function ($model) {
            if ($model->organization_id === null) {
                $id = app(Tenancy::class)->id();
                if ($id !== null) {
                    $model->organization_id = $id;
                }
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Bypass the tenant scope — platform/console use only. */
    public function scopeWithoutOrganizationScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(OrganizationScope::class);
    }
}
