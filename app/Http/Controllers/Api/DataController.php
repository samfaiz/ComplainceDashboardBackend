<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiSource;
use App\Models\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DataController extends Controller
{
    /** Allowed normalized fields for filtering / sorting / grouping. */
    private const FIELDS = [
        'hostname', 'os_platform', 'os_version', 'agent_version',
        'health_status', 'compliance_status', 'ip_address', 'mac_address',
        'last_seen_at', 'external_id', 'is_isolated',
    ];

    /** Paginated, filterable, sortable view of the latest pulled data. */
    public function index(Request $request, ApiSource $source): JsonResponse
    {
        $this->authorizeSource($request, $source);

        if (! $source->latest_snapshot_id) {
            return response()->json(['data' => [], 'meta' => ['total' => 0], 'columns' => self::FIELDS]);
        }

        $query = Endpoint::query()->where('snapshot_id', $source->latest_snapshot_id);

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('hostname', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%")
                    ->orWhere('os_version', 'like', "%{$search}%")
                    ->orWhere('external_id', 'like', "%{$search}%");
            });
        }

        foreach (['os_platform', 'health_status', 'compliance_status', 'agent_version'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        $sort = in_array($request->input('sort'), self::FIELDS, true) ? $request->input('sort') : 'hostname';
        $dir = $request->input('dir') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sort, $dir);

        $perPage = min((int) $request->input('per_page', 25), 200);
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (Endpoint $e) => [
                'id' => $e->id,
                'external_id' => $e->external_id,
                'hostname' => $e->hostname,
                'os_platform' => $e->os_platform,
                'os_version' => $e->os_version,
                'agent_version' => $e->agent_version,
                'health_status' => $e->health_status,
                'compliance_status' => $e->compliance_status,
                'last_seen_at' => $e->last_seen_at,
                'ip_address' => $e->ip_address,
                'mac_address' => $e->mac_address,
                'is_isolated' => $e->is_isolated,
                'extra' => $e->extra,
            ])->all(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
            'columns' => self::FIELDS,
            'captured_at' => optional($source->latestSnapshot)->captured_at,
        ]);
    }

    /** Latest snapshot rollup powering the default dashboard. */
    public function summary(Request $request, ApiSource $source): JsonResponse
    {
        $this->authorizeSource($request, $source);

        $snapshot = $source->latestSnapshot;

        return response()->json([
            'summary' => $snapshot?->summary,
            'captured_at' => $snapshot?->captured_at,
            'endpoint_count' => $snapshot?->endpoint_count ?? 0,
            'last_status' => $source->last_status,
            'last_error' => $source->last_error,
        ]);
    }

    /** Group-by count over any whitelisted field — backs custom chart widgets. */
    public function aggregate(Request $request, ApiSource $source): JsonResponse
    {
        $this->authorizeSource($request, $source);

        $field = $request->input('field', 'os_platform');
        abort_unless(in_array($field, self::FIELDS, true), 422, 'Unsupported field.');

        if (! $source->latest_snapshot_id) {
            return response()->json(['field' => $field, 'buckets' => []]);
        }

        $rows = Endpoint::query()
            ->where('snapshot_id', $source->latest_snapshot_id)
            ->selectRaw("COALESCE($field, 'Unknown') as label, COUNT(*) as value")
            ->groupBy('label')
            ->orderByDesc('value')
            ->limit((int) $request->input('limit', 30))
            ->get();

        return response()->json([
            'field' => $field,
            'buckets' => $rows->map(fn ($r) => ['label' => (string) $r->label, 'value' => (int) $r->value])->all(),
        ]);
    }

    /** Time-series across snapshots for trend charts. */
    public function trends(Request $request, ApiSource $source): JsonResponse
    {
        $this->authorizeSource($request, $source);

        $limit = min((int) $request->input('limit', 90), 365);

        $snapshots = $source->snapshots()
            ->orderByDesc('captured_at')
            ->limit($limit)
            ->get(['captured_at', 'endpoint_count', 'summary'])
            ->reverse()
            ->values();

        $series = $snapshots->map(function ($s) {
            $sum = $s->summary ?? [];

            return [
                'captured_at' => $s->captured_at,
                'total' => $s->endpoint_count,
                'online' => $sum['online'] ?? 0,
                'stale' => $sum['stale'] ?? 0,
                'offline' => $sum['offline'] ?? 0,
                'compliant' => $sum['compliant'] ?? 0,
                'non_compliant' => $sum['non_compliant'] ?? 0,
                'compliance_pct' => $sum['compliance_pct'] ?? 0,
            ];
        });

        return response()->json(['series' => $series->all()]);
    }

    private function authorizeSource(Request $request, ApiSource $source): void
    {
        abort_unless(
            $source->user_id === $request->user()->id || $request->user()->isAdmin(),
            403,
            'You do not have access to this source.'
        );
    }
}
