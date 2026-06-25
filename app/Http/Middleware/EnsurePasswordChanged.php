<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces a first-login password change. While must_change_password is true
 * (e.g. after an admin creates the account or resets the password), every
 * request is blocked except the few needed to change the password or sign out.
 *
 * This runs before the MFA gate, so a brand-new user sets their password first.
 */
class EnsurePasswordChanged
{
    private array $allowed = [
        'api/me',
        'api/logout',
        'api/password',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password && ! in_array($request->path(), $this->allowed, true)) {
            return response()->json([
                'message' => 'You must change your password before continuing.',
                'password_change_required' => true,
            ], 403);
        }

        return $next($request);
    }
}
