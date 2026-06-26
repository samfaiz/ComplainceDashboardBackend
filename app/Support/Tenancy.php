<?php

namespace App\Support;

use Closure;

/**
 * Holds the active organization (tenant) for the current request/process.
 *
 * Bound as a singleton (see AppServiceProvider). The OrganizationScope reads
 * this to filter every tenant model. When the id is null there is NO tenant
 * context — the correct state for guests (pre-login), the platform owner
 * (super_admin), and console/scheduler processes. In those states tenant
 * scopes are not applied.
 */
class Tenancy
{
    private ?int $organizationId = null;

    public function set(?int $organizationId): void
    {
        $this->organizationId = $organizationId;
    }

    public function id(): ?int
    {
        return $this->organizationId;
    }

    public function hasContext(): bool
    {
        return $this->organizationId !== null;
    }

    public function forget(): void
    {
        $this->organizationId = null;
    }

    /**
     * Run a callback bound to a specific organization, restoring the previous
     * context afterwards. Used by the scheduler and notification dispatch which
     * act on behalf of an org without an HTTP request.
     *
     * @template T
     *
     * @param  Closure():T  $callback
     * @return T
     */
    public function runFor(?int $organizationId, Closure $callback)
    {
        $previous = $this->organizationId;
        $this->organizationId = $organizationId;

        try {
            return $callback();
        } finally {
            $this->organizationId = $previous;
        }
    }
}
