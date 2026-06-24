<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dashboard;
use App\Support\DefaultDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'dashboards' => $request->user()->dashboards()->latest('is_default')->latest()->get(),
        ]);
    }

    /** Return the user's default dashboard, creating a sensible one on first use. */
    public function default(Request $request): JsonResponse
    {
        $user = $request->user();
        $dashboard = $user->dashboards()->where('is_default', true)->first();

        if (! $dashboard) {
            $dashboard = $user->dashboards()->create([
                'name' => 'Default Dashboard',
                'is_default' => true,
                'api_source_id' => $user->apiSources()->value('id'),
                'layout' => DefaultDashboard::layout(),
            ]);
        }

        return response()->json(['dashboard' => $dashboard]);
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

        return response()->json(['dashboard' => $dashboard], 201);
    }

    public function show(Request $request, Dashboard $dashboard): JsonResponse
    {
        $this->authorize($request, $dashboard);

        return response()->json(['dashboard' => $dashboard]);
    }

    public function update(Request $request, Dashboard $dashboard): JsonResponse
    {
        $this->authorize($request, $dashboard);
        abort_unless($request->user()->canManage(), 403);

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

        return response()->json(['dashboard' => $dashboard->fresh()]);
    }

    public function destroy(Request $request, Dashboard $dashboard): JsonResponse
    {
        $this->authorize($request, $dashboard);
        abort_unless($request->user()->canManage(), 403);
        $dashboard->delete();

        return response()->json(['message' => 'Dashboard deleted.']);
    }

    private function authorize(Request $request, Dashboard $dashboard): void
    {
        abort_unless($dashboard->user_id === $request->user()->id, 403);
    }

    private function validateSourceOwnership(Request $request, ?int $sourceId): void
    {
        if ($sourceId !== null && ! $request->user()->apiSources()->whereKey($sourceId)->exists()) {
            abort(422, 'Invalid source.');
        }
    }
}
