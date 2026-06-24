<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Usage: ->middleware('role:admin') or 'role:admin,analyst'
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! empty($roles) && ! $user->hasRole(...$roles)) {
            return response()->json(['message' => 'This action is forbidden for your role.'], 403);
        }

        return $next($request);
    }
}
