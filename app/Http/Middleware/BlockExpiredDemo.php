<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hard-stops a demo account the moment its 1-hour window elapses — even if the
 * browser timer was bypassed (tab reopened, direct API call). Tears down the
 * session so the demo can never be used again; demos:purge removes the rows.
 */
class BlockExpiredDemo
{
    public function handle(Request $request, Closure $next): Response
    {
        $org = $request->user()?->organization;

        if ($org && $org->isExpired()) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json([
                'message' => 'This demo session has expired. Thanks for trying it out!',
                'demo_expired' => true,
            ], 401);
        }

        return $next($request);
    }
}
