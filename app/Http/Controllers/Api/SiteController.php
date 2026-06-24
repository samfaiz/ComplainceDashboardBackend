<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Security\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $sites = $request->user()->sites()
            ->withCount('sources')
            ->orderBy('name')
            ->get()
            ->map(fn (Site $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'sources_count' => $s->sources_count,
            ]);

        return response()->json(['sites' => $sites]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->canManage(), 403);

        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);

        $site = $request->user()->sites()->create($data);
        $this->audit->log('site.created', $request->user(), $site, ['name' => $site->name]);

        return response()->json(['site' => ['id' => $site->id, 'name' => $site->name, 'sources_count' => 0]], 201);
    }

    public function update(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        abort_unless($request->user()->canManage(), 403);

        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $site->update($data);
        $this->audit->log('site.renamed', $request->user(), $site, ['name' => $site->name]);

        return response()->json(['site' => ['id' => $site->id, 'name' => $site->name]]);
    }

    public function destroy(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        abort_unless($request->user()->canManage(), 403);

        // Sources are detached (site_id set null), not deleted.
        $this->audit->log('site.deleted', $request->user(), $site, ['name' => $site->name]);
        $site->delete();

        return response()->json(['message' => 'Site deleted. Its sources were kept (unassigned).']);
    }

    /** Assign / unassign a source to this site. */
    public function assign(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        abort_unless($request->user()->canManage(), 403);

        $data = $request->validate([
            'source_id' => ['required', 'integer'],
            'attach' => ['required', 'boolean'],
        ]);

        $source = $request->user()->apiSources()->whereKey($data['source_id'])->firstOrFail();
        $source->forceFill(['site_id' => $data['attach'] ? $site->id : null])->save();

        return response()->json(['message' => 'Updated.']);
    }

    private function authorizeSite(Request $request, Site $site): void
    {
        abort_unless($site->user_id === $request->user()->id, 403, 'You do not have access to this site.');
    }
}
