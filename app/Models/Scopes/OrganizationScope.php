<?php

namespace App\Models\Scopes;

use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filters every tenant model by the active organization. When there is no
 * tenant context (guest, platform owner, console) the scope is a no-op, so
 * login, the scheduler, and cross-org platform queries keep working.
 */
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $organizationId = app(Tenancy::class)->id();

        if ($organizationId !== null) {
            $builder->where($model->getTable().'.organization_id', $organizationId);
        }
    }
}
