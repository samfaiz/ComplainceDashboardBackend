<?php

namespace App\Services\Security;

use App\Models\LoginEvent;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\Request;

/**
 * Centralizes login-time security: known-IP baselining + red-flagging on a new
 * IP, brute-force lockout, last-seen/active tracking and login-event auditing.
 */
class LoginSecurityService
{
    public function __construct(
        private AuditLogger $audit,
        private NotificationService $notifications,
    ) {}

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

        if ($isNewIp) {
            $this->notifications->dispatch('login.new_ip', [
                'user' => ['name' => $user->name, 'email' => $user->email],
                'ip' => $ip,
                'user_agent' => substr((string) $request->userAgent(), 0, 240),
                'when' => now()->toDateTimeString(),
            ]);
        }

        return $isNewIp;
    }

    public function registerFailure(?User $user, string $email, Request $request, string $reason): void
    {
        // Only genuine wrong attempts (bad password / failed MFA code) escalate;
        // attempts that bounce off an existing lock or a disabled account don't count.
        if ($user && in_array($reason, ['bad_credentials', 'mfa_failed'], true)) {
            $attempts = (int) $user->failed_login_attempts + 1;
            $lockAfter = (int) config('security.lockout_after_attempts', 3);
            $disableAfter = (int) config('security.disable_after_attempts', 6);
            $lockMinutes = (int) config('security.lockout_minutes', 1);

            // Stage 2 — too many failures: disable the account and alert admins.
            if ($disableAfter > 0 && $attempts >= $disableAfter && ! $user->isSuperAdmin()) {
                $user->forceFill([
                    'failed_login_attempts' => $attempts,
                    'locked_until' => null,
                    'is_active' => false,
                ])->save();

                $this->recordEvent($user, $request, false, false, $reason);
                $this->audit->log('auth.account_disabled', $user, null, ['attempts' => $attempts], (string) $request->ip());

                $this->notifications->dispatch('account.disabled', [
                    'user' => ['name' => $user->name, 'email' => $user->email, 'role' => $user->role],
                    'attempts' => $attempts,
                    'ip' => (string) $request->ip(),
                    'when' => now()->toDateTimeString(),
                ]);

                return;
            }

            // Stage 1 — short lockout each time the threshold is hit (e.g. every 3).
            $attrs = ['failed_login_attempts' => $attempts];
            if ($lockAfter > 0 && $attempts % $lockAfter === 0) {
                $until = now()->addMinutes($lockMinutes);
                $attrs['locked_until'] = $until;

                $this->notifications->dispatch('account.locked', [
                    'user' => ['name' => $user->name, 'email' => $user->email],
                    'until' => $until->toDateTimeString(),
                    'reason' => 'Too many failed sign-in attempts',
                ]);
            }
            $user->forceFill($attrs)->save();
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
