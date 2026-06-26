<?php

namespace App\Http\Controllers\Api;

use App\Actions\ProvisionOrganization;
use App\Http\Controllers\Controller;
use App\Models\ApiSource;
use App\Models\Organization;
use App\Models\User;
use App\Services\Security\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform-owner (super_admin) cross-organization management. These routes run
 * without an org scope; the platform owner may "enter" an org to view/manage it
 * scoped (the entered org id lives in the session and is read by SetTenant).
 */
class PlatformController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function organizations(): JsonResponse
    {
        $orgs = Organization::orderBy('name')->get()->map(fn (Organization $o) => $this->payload($o));

        return response()->json(['organizations' => $orgs]);
    }

    public function store(Request $request, ProvisionOrganization $provision): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email', 'max:200', 'unique:users,email'],
            'admin_password' => ['required', 'string', 'min:12'],
        ]);

        $org = $provision->create($data['name'], [
            'name' => $data['admin_name'],
            'email' => $data['admin_email'],
            'password' => $data['admin_password'],
            'must_change_password' => true,
        ], $request->user());

        $this->audit->log('platform.org_created', $request->user(), $org, ['name' => $org->name]);

        return response()->json(['organization' => $this->payload($org)], 201);
    }

    public function show(Organization $organization): JsonResponse
    {
        return response()->json(['organization' => $this->payload($organization)]);
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $organization->fill($data)->save();
        $this->audit->log('platform.org_updated', $request->user(), $organization, $data);

        return response()->json(['organization' => $this->payload($organization->fresh())]);
    }

    public function destroy(Request $request, Organization $organization): JsonResponse
    {
        $name = $organization->name;

        // Remove members first — their FK is nullOnDelete, which would otherwise
        // orphan them; their owned data cascades. Remaining org-stamped rows
        // cascade when the organization itself is deleted.
        User::withoutOrganizationScope()->where('organization_id', $organization->id)->delete();
        $organization->delete();

        $this->audit->log('platform.org_deleted', $request->user(), null, ['name' => $name]);

        return response()->json(['message' => 'Organization deleted.']);
    }

    /** Enter an org — subsequent requests are scoped to it (see SetTenant). */
    public function enter(Request $request, Organization $organization): JsonResponse
    {
        $request->session()->put('platform.organization_id', $organization->id);

        return response()->json(['entered' => ['id' => $organization->id, 'name' => $organization->name]]);
    }

    public function exit(Request $request): JsonResponse
    {
        $request->session()->forget('platform.organization_id');

        return response()->json(['exited' => true]);
    }

    private function payload(Organization $o): array
    {
        return [
            'id' => $o->id,
            'name' => $o->name,
            'slug' => $o->slug,
            'is_active' => $o->is_active,
            'created_at' => $o->created_at,
            'users_count' => User::withoutOrganizationScope()->where('organization_id', $o->id)->count(),
            'sources_count' => ApiSource::withoutOrganizationScope()->where('organization_id', $o->id)->count(),
        ];
    }
}
