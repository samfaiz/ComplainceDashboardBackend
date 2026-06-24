<?php

namespace App\Services\Security;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function log(string $action, ?User $user = null, ?Model $target = null, array $meta = [], ?string $ip = null): void
    {
        AuditLog::create([
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
