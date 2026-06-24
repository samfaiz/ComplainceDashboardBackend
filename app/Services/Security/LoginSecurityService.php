<?php

namespace App\Services\Security;

use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Centralizes login-time security: known-IP baselining + red-flagging on a new
 * IP, brute-force lockout, last-seen/active tracking and login-event auditing.
 */
class LoginSecurityService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Record the IP against the user's known-IP baseline.
     *
     * @return bool whether this is a brand-new IP relative to an existing baseline
     */
    public function trackIp(User $user, string $ip): bool
    {
        $known = $user->knownIps()->where('ip_address', $ip)->first();

        if ($known) {
            $known->increment('login_count');
            $known->forceFill(['last_seen_at' => now()])->save();

            return false;
        }

        $hadBaseline = $user->knownIps()->exists();

        $user->knownIps()->create([
            'ip_address' => $ip,
            'trusted' => ! $hadBaseline,   // very first IP becomes the trusted baseline
            'login_count' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        // New IP after a baseline already exists => raise the red flag.
        if ($hadBaseline && config('security.flag_new_ip', true)) {
            $user->forceFill(['ip_flagged' => true])->save();
        }

        return $hadBaseline;
    }

    public function registerSuccess(User $user, Request $request): bool
    {
        $ip = (string) $request->ip();
        $isNewIp = $this->trackIp($user, $ip);

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
            'current_ip' => $ip,
            'last_seen_at' => now(),
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();

        $this->recordEvent($user, $request, true, $isNewIp, null);
        $this->audit->log('auth.login', $user, null, ['new_ip' => $isNewIp], $ip);

        return $isNewIp;
    }

    public function registerFailure(?User $user, string $email, Request $request, string $reason): void
    {
        if ($user) {
            $user->increment('failed_login_attempts');

            $max = (int) config('security.max_login_attempts', 8);
            if ($max > 0 && $user->fresh()->failed_login_attempts >= $max) {
                $user->forceFill([
                    'locked_until' => now()->addMinutes((int) config('security.lockout_minutes', 15)),
                ])->save();
            }
        }

        $this->recordEvent($user, $request, false, false, $reason);
        $this->audit->log('auth.login_failed', $user, null, compact('email', 'reason'), (string) $request->ip());
    }

    private function recordEvent(?User $user, Request $request, bool $ok, bool $newIp, ?string $reason): void
    {
        LoginEvent::create([
            'user_id' => $user?->id,
            'email' => $user?->email ?? $request->input('email'),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
            'successful' => $ok,
            'is_new_ip' => $newIp,
            'failure_reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
