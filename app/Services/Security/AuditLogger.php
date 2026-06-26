<?php

namespace App\Services\Security;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    public function __construct(private Tenancy $tenancy) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function log(string $action, ?User $user = null, ?Model $target = null, array $meta = [], ?string $ip = null): void
    {
        AuditLog::create([
            // Tenant context when present (HTTP), else the acting user's org so
            // login-time events (logged on a context-free public route) still land
            // in the right organization.
            'organization_id' => $this->tenancy->id() ?? $user?->organization_id,
            'user_id' => $user?->id,
            'action' => $action,
            'target_type' => $target ? class_basename($target) : null,
            'target_id' => $target?->getKey(),
            'meta' => $meta ?: null,
            'ip_address' => $ip ?? request()->ip(),
            'created_at' => now(),
        ]);
    }
}
