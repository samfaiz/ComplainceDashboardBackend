<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects requests from an account that was disabled mid-session and tears down
 * its session, so an admin "disable account" takes effect on the user's very
 * next request — even with cookie-based sessions that can't be purged server-side.
 */
class EnsureActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json([
                'message' => 'Your account is no longer active. Please contact an administrator.',
            ], 403);
        }

        return $next($request);
    }
}
