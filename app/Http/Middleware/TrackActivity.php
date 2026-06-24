<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps last_seen_at / current_ip fresh for authenticated users so the admin
 * panel can show who is online and detect mid-session IP changes. Throttled to
 * at most one write per minute per user.
 */
class TrackActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();

        if ($user && (! $user->last_seen_at || $user->last_seen_at->lt(now()->subMinute()))) {
            $ip = (string) $request->ip();
            $attrs = ['last_seen_at' => now()];

            // Flag a mid-session IP change too, not just at login.
            if ($user->current_ip && $user->current_ip !== $ip && config('security.flag_new_ip', true)) {
                $known = $user->knownIps()->where('ip_address', $ip)->exists();
                if (! $known) {
                    $attrs['ip_flagged'] = true;
                }
            }

            $attrs['current_ip'] = $ip;
            $user->forceFill($attrs)->save();
        }

        return $response;
    }
}
