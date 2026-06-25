<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dashboard;
use App\Services\Security\AuditLogger;
use App\Support\DefaultDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /** Dashboards the user can open: their own + any admin has assigned to them. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $owned = $user->dashboards()->latest('is_default')->latest()->get()
            ->map(fn (Dashboard $d) => $this->row($d, owned: true));

        $assigned = $user->assignedDashboards()->with('user:id,name')->get()
            ->reject(fn (Dashboard $d) => $owned->contains('id', $d->id))
            ->map(fn (Dashboard $d) => $this->row($d, owned: false));

        return response()->json([
            'dashboards' => $owned->merge($assigned)->values(),
        ]);
    }

    /**
     * Return the user's default dashboard.
     * - Managers: their own default (auto-created on first use).
     * - Viewers (or anyone without an owned default): first assigned dashboard, if any.
     */
    public function default(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->canManage()) {
            $dashboard = $user->dashboards()->where('is_default', true)->first()
                ?? $user->dashboards()->create([
                    'name' => 'Default Dashboard',
                    'is_default' => true,
                    'api_source_id' => $user->apiSources()->value('id'),
                    'layout' => DefaultDashboard::layout(),
                ]);

            return response()->json(['dashboard' => $this->row($dashboard, owned: true)]);
        }

        // Viewer path: prefer assigned dashboard; fall back to none.
        $assigned = $user->assignedDashboards()->orderBy('dashboard_user.created_at')->first();

        if (! $assigned) {
            return response()->json(['dashboard' => null]);
        }

        $this->audit->log('dashboard.viewed', $user, $assigned, [
            'dashboard_name' => $assigned->name,
            'owner_id' => $assigned->user_id,
            'via' => 'default',
        ]);

        return response()->json(['dashboard' => $this->row($assigned, owned: false)]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->canManage(), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'api_source_id' => ['nullable', 'integer'],
            'layout' => ['nullable', 'array'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $this->validateSourceOwnership($request, $data['api_source_id'] ?? null);

        if (! empty($data['is_default'])) {
            $request->user()->dashboards()->update(['is_default' => false]);
        }

        $dashboard = $request->user()->dashboards()->create([
            'name' => $data['name'],
            'api_source_id' => $data['api_source_id'] ?? null,
            'layout' => $data['layout'] ?? DefaultDashboard::layout(),
            'is_default' => $data['is_default'] ?? false,
        ]);

        $this->audit->log('dashboard.created', $request->user(), $dashboard, ['name' => $dashboard->name]);

        return response()->json(['dashboard' => $this->row($dashboard, owned: true)], 201);
    }

    public function show(Request $request, Dashboard $dashboard): JsonResponse
    {
        $owned = $this->authorizeRead($request, $dashboard);

        if (! $owned) {
            $this->audit->log('dashboard.viewed', $request->user(), $dashboard, [
                'dashboard_name' => $dashboard->name,
                'owner_id' => $dashboard->user_id,
                'via' => 'switch',
            ]);
        }

        return response()->json(['dashboard' => $this->row($dashboard, owned: $owned)]);
    }

    public function update(Request $request, Dashboard $dashboard): JsonResponse
    {
        $this->authorizeWrite($request, $dashboard);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'api_source_id' => ['nullable', 'integer'],
            'layout' => ['sometimes', 'array'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('api_source_id', $data)) {
            $this->validateSourceOwnership($request, $data['api_source_id']);
        }

        if (! empty($data['is_default'])) {
            $request->user()->dashboards()->where('id', '!=', $dashboard->id)->update(['is_default' => false]);
        }

        $dashboard->update($data);

        $this->audit->log('dashboard.updated', $request->user(), $dashboard, array_intersect_key($data, array_flip(['name', 'is_default'])));

        return response()->json(['dashboard' => $this->row($dashboard->fresh(), owned: true)]);
    }

    public function destroy(Request $request, Dashboard $dashboard): JsonResponse
    {
        $this->authorizeWrite($request, $dashboard);

        $name = $dashboard->name;
        $dashboard->delete();

        $this->audit->log('dashboard.deleted', $request->user(), $dashboard, ['name' => $name]);

        return response()->json(['message' => 'Dashboard deleted.']);
    }

    /** Read access: owner OR an assigned viewer. */
    private function authorizeRead(Request $request, Dashboard $dashboard): bool
    {
        $userId = $request->user()->id;

        if ($dashboard->user_id === $userId) {
            return true;
        }

        if ($dashboard->assignees()->whereKey($userId)->exists()) {
            return false;
        }

        abort(403);
    }

    /** Write access: owner with manage permission. */
    private function authorizeWrite(Request $request, Dashboard $dashboard): void
    {
        abort_unless($dashboard->user_id === $request->user()->id, 403);
        abort_unless($request->user()->canManage(), 403);
    }

    private function validateSourceOwnership(Request $request, ?int $sourceId): void
    {
        if ($sourceId !== null && ! $request->user()->apiSources()->whereKey($sourceId)->exists()) {
            abort(422, 'Invalid source.');
        }
    }

    /** Serialize a dashboard with read-only metadata for the requesting user. */
    private function row(Dashboard $dashboard, bool $owned): array
    {
        return [
            'id' => $dashboard->id,
            'user_id' => $dashboard->user_id,
            'api_source_id' => $dashboard->api_source_id,
            'name' => $dashboard->name,
            'is_default' => $dashboard->is_default,
            'layout' => $dashboard->layout,
            'owned' => $owned,
            'read_only' => ! $owned,
            'owner_name' => $owned ? null : ($dashboard->relationLoaded('user') ? $dashboard->user?->name : $dashboard->user()->value('name')),
            'updated_at' => $dashboard->updated_at,
        ];
    }
}
