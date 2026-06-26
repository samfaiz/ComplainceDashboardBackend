<?php

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the active organization for the request, right after authentication.
 *
 * - Org users (admin/analyst/viewer): scoped to their own organization. A
 *   suspended organization blocks everything except viewing /me and signing out.
 * - Platform owner (super_admin, organization_id = null): no tenant context by
 *   default, so they operate at the platform level. They may "enter" a specific
 *   org (stored in the session) to view/manage it scoped.
 */
class SetTenant
{
    /** Still reachable while an org is suspended, so the user can sign out. */
    private array $suspendedAllowed = ['api/me', 'api/logout'];

    public function __construct(private Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if ($user->isSuperAdmin()) {
            // Platform owner: optional impersonation of a single org.
            $entered = $request->session()->get('platform.organization_id');
            $this->tenancy->set($entered ? (int) $entered : null);

            return $next($request);
        }

        // Org-bound user must belong to an organization.
        if ($user->organization_id === null) {
            abort(403, 'Your account is not assigned to an organization.');
        }

        $org = $user->organization;
        if ($org && ! $org->is_active && ! in_array($request->path(), $this->suspendedAllowed, true)) {
            abort(423, 'This organization has been suspended. Contact your platform administrator.');
        }

        $this->tenancy->set((int) $user->organization_id);

        return $next($request);
    }
}
