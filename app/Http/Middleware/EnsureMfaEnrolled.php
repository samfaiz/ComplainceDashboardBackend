<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When an admin has flagged a user as "MFA required", that user must finish
 * TOTP enrollment before using the app. Until mfa_enabled is true, every
 * request is blocked except the handful needed to enroll or sign out.
 */
class EnsureMfaEnrolled
{
    /** Paths still reachable while enrollment is pending. */
    private array $allowed = [
        'api/me',
        'api/logout',
        'api/mfa/setup',
        'api/mfa/confirm',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user
            && $user->mfa_required
            && ! $user->mfa_enabled
            && ! $user->must_change_password   // password change takes priority
            && ! in_array($request->path(), $this->allowed, true)
        ) {
            return response()->json([
                'message' => 'Two-factor authentication setup is required before continuing.',
                'mfa_setup_required' => true,
            ], 403);
        }

        return $next($request);
    }
}
