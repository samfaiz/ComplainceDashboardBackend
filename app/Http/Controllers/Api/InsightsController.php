<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dashboard;
use App\Models\Endpoint;
use App\Models\EndpointColumnLayout;
use App\Models\Snapshot;
use App\Models\User;
use App\Services\Ingest\RuleEvaluator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Scope-aware data endpoints. A "scope" selects which sources to aggregate:
 *   - all            → every source the user owns ("All sites")
 *   - site:{id}      → all sources assigned to that site
 *   - source:{id}    → a single source
 *
 * This powers the dashboard/data views so a user can monitor one source, one
 * site, or their entire estate in a single pane.
 */
class InsightsController extends Controller
{
    private const FIELDS = [
        'hostname', 'os_platform', 'os_version', 'agent_version',
        'health_status', 'compliance_status', 'ip_address', 'mac_address',
        'last_seen_at', 'external_id', 'is_isolated',
    ];

    public function summary(Request $request): JsonResponse
    {
        $sources = $this->resolveSources($request);
        $snapshotIds = $this->snapshotIds($sources);

        $snapshots = Snapshot::whereIn('id', $snapshotIds)->get(['summary', 'captured_at', 'endpoint_count']);
        $summary = $this->mergeSummaries($snapshots->pluck('summary')->filter()->all());

        $failing = $sources->where('last_status', 'failed');
        $lastError = $failing->isNotEmpty()
            ? $failing->count().' source(s) failed to refresh: '.$failing->pluck('name')->implode(', ')
            : null;

        return response()->json([
            'summary' => $summary,
            'captured_at' => $snapshots->max('captured_at'),
            'endpoint_count' => $summary['total'] ?? 0,
            'source_count' => $sources->count(),
            'last_error' => $lastError,
        ]);
    }

    public function aggregate(Request $request): JsonResponse
    {
        $field = $request->input('field', 'os_platform');
        abort_unless(in_array($field, self::FIELDS, true), 422, 'Unsupported field.');

        $snapshotIds = $this->snapshotIds($this->resolveSources($request));

        if (empty($snapshotIds)) {
            return response()->json(['field' => $field, 'buckets' => []]);
        }

        $rows = Endpoint::query()
            ->whereIn('snapshot_id', $snapshotIds)
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

    public function trends(Request $request): JsonResponse
    {
        $sources = $this->resolveSources($request);
        $sourceIds = $sources->pluck('id')->all();

        if (empty($sourceIds)) {
            return response()->json(['series' => []]);
        }

        $snapshots = Snapshot::whereIn('api_source_id', $sourceIds)
            ->orderBy('captured_at')
            ->get(['api_source_id', 'captured_at', 'endpoint_count', 'summary']);

        // Keep the latest snapshot per (source, day) so multiple daily pulls don't double-count.
        $latestPerSourceDay = [];
        foreach ($snapshots as $s) {
            $day = $s->captured_at->format('Y-m-d');
            $key = $s->api_source_id.'|'.$day;
            if (! isset($latestPerSourceDay[$key]) || $s->captured_at->gt($latestPerSourceDay[$key]->captured_at)) {
                $latestPerSourceDay[$key] = $s;
            }
        }

        // Sum across sources per day.
        $byDay = [];
        foreach ($latestPerSourceDay as $s) {
            $day = $s->captured_at->format('Y-m-d');
            $sum = $s->summary ?? [];
            $byDay[$day] ??= ['captured_at' => $day, 'total' => 0, 'online' => 0, 'stale' => 0, 'offline' => 0, 'compliant' => 0, 'non_compliant' => 0];
            $byDay[$day]['total'] += $s->endpoint_count;
            foreach (['online', 'stale', 'offline', 'compliant', 'non_compliant'] as $k) {
                $byDay[$day][$k] += $sum[$k] ?? 0;
            }
        }

        ksort($byDay);
        $series = array_map(function ($row) {
            $row['compliance_pct'] = $row['total'] > 0 ? round($row['compliant'] / $row['total'] * 100, 1) : 0;

            return $row;
        }, array_values($byDay));

        $limit = min((int) $request->input('limit', 90), 365);

        return response()->json(['series' => array_slice($series, -$limit)]);
    }

    /** Count endpoints in scope that match a user-defined rule. */
    public function evaluate(Request $request, RuleEvaluator $evaluator): JsonResponse
    {
        $request->validate([
            'rule' => ['required', 'array'],
            'rule.match' => ['nullable', Rule::in(['all', 'any'])],
            'rule.conditions' => ['required', 'array', 'min:1'],
            'rule.conditions.*.field' => ['required', 'string'],
            'rule.conditions.*.op' => ['required', 'string'],
        ]);

        $snapshotIds = $this->snapshotIds($this->resolveSources($request));

        return response()->json($evaluator->count($snapshotIds, $request->input('rule')));
    }

    /** Return the endpoints matching a rule — powers stat/gauge drill-down. */
    public function ruleData(Request $request, RuleEvaluator $evaluator): JsonResponse
    {
        $request->validate([
            'rule' => ['required', 'array'],
            'rule.match' => ['nullable', Rule::in(['all', 'any'])],
            'rule.conditions' => ['required', 'array', 'min:1'],
        ]);

        $snapshotIds = $this->snapshotIds($this->resolveSources($request));
        $query = $evaluator->buildQuery($snapshotIds, $request->input('rule'));

        if (! $query) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }

        $sort = in_array($request->input('sort'), self::FIELDS, true) ? $request->input('sort') : 'hostname';
        $dir = $request->input('dir') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sort, $dir);

        $page = $query->paginate(min((int) $request->input('per_page', 25), 200));

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
            ])->all(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $snapshotIds = $this->snapshotIds($this->resolveSources($request));

        if (empty($snapshotIds)) {
            return response()->json(['data' => [], 'meta' => ['total' => 0], 'columns' => self::FIELDS]);
        }

        $query = Endpoint::query()->whereIn('snapshot_id', $snapshotIds);

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('hostname', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%")
                    ->orWhere('os_version', 'like', "%{$search}%")
                    ->orWhere('external_id', 'like', "%{$search}%");
            });
        }

        // Drill-down filters: any aggregatable field can be passed exactly.
        foreach (['os_platform', 'os_version', 'health_status', 'compliance_status', 'agent_version', 'ip_address', 'mac_address'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }
        if ($request->filled('is_isolated')) {
            $query->where('is_isolated', filter_var($request->input('is_isolated'), FILTER_VALIDATE_BOOL));
        }

        $sort = in_array($request->input('sort'), self::FIELDS, true) ? $request->input('sort') : 'hostname';
        $dir = $request->input('dir') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sort, $dir);

        $page = $query->paginate(min((int) $request->input('per_page', 25), 200));

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
                // Only ship the (potentially large) raw record when a raw column is shown.
                'raw' => $request->boolean('with_raw') ? $e->raw : null,
            ])->all(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
            'columns' => self::FIELDS,
        ]);
    }

    /** Available columns (standard + mapped custom) plus saved layouts for the Endpoint table. */
    public function columns(Request $request): JsonResponse
    {
        $sources = $this->resolveSources($request);

        $standard = [
            'hostname' => 'Hostname',
            'os_platform' => 'OS Platform',
            'os_version' => 'OS Version',
            'agent_version' => 'Agent / Sensor Version',
            'health_status' => 'Health',
            'compliance_status' => 'Compliance',
            'last_seen_at' => 'Last Seen',
            'ip_address' => 'IP Address',
            'mac_address' => 'MAC Address',
            'external_id' => 'External ID',
            'is_isolated' => 'Isolated',
        ];

        $available = [];
        foreach ($standard as $key => $label) {
            $available[] = ['key' => $key, 'label' => $label, 'group' => 'standard'];
        }

        // Custom mapped fields live under `extra.<slug>`; collect across in-scope sources.
        $seen = [];
        foreach ($sources as $source) {
            foreach (array_keys($source->field_mappings ?? []) as $slug) {
                if (! array_key_exists($slug, $standard) && ! isset($seen[$slug])) {
                    $seen[$slug] = true;
                    $available[] = [
                        'key' => 'extra.'.$slug,
                        'label' => ucfirst(str_replace('_', ' ', $slug)),
                        'group' => 'custom',
                    ];
                }
            }
        }

        // Raw API fields — every top-level key the source returns (display-only
        // unless also mapped as a custom field). Sampled from recent endpoints.
        $snapshotIds = $this->snapshotIds($sources);
        if (! empty($snapshotIds)) {
            $rawKeys = [];
            $sample = Endpoint::whereIn('snapshot_id', $snapshotIds)
                ->whereNotNull('raw')
                ->limit(100)
                ->get(['raw']);
            foreach ($sample as $endpoint) {
                if (is_array($endpoint->raw)) {
                    foreach (array_keys($endpoint->raw) as $k) {
                        $rawKeys[$k] = true;
                    }
                }
            }
            ksort($rawKeys);
            foreach (array_keys($rawKeys) as $k) {
                $available[] = [
                    'key' => 'raw.'.$k,
                    'label' => ucfirst(trim(preg_replace('/(?<!^)(?=[A-Z])/u', ' ', (string) $k))),
                    'group' => 'raw',
                ];
            }
        }

        return response()->json([
            'available' => $available,
            'default' => EndpointColumnLayout::whereNull('user_id')->value('columns'),
            'mine' => EndpointColumnLayout::where('user_id', $request->user()->id)->value('columns'),
        ]);
    }

    /** Save the requesting user's column layout, or (admins only) the shared default. */
    public function saveColumns(Request $request): JsonResponse
    {
        $data = $request->validate([
            'columns' => ['present', 'array'],
            'columns.*.field' => ['required', 'string', 'max:80'],
            'columns.*.label' => ['required', 'string', 'max:80'],
            'columns.*.visible' => ['required', 'boolean'],
            'as_default' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $asDefault = ! empty($data['as_default']);

        if ($asDefault) {
            abort_unless($user->isAdmin(), 403, 'Only admins can set the shared default layout.');
        }

        $layout = EndpointColumnLayout::firstOrNew(['user_id' => $asDefault ? null : $user->id]);
        $layout->columns = $data['columns'];
        $layout->updated_by_user_id = $user->id;
        $layout->save();

        return response()->json(['ok' => true]);
    }

    /** Clear the user's personal layout so they fall back to the shared default. */
    public function resetColumns(Request $request): JsonResponse
    {
        EndpointColumnLayout::where('user_id', $request->user()->id)->delete();

        return response()->json(['ok' => true]);
    }

    /* ------------------------------------------------------------------ */

    private function resolveSources(Request $request): Collection
    {
        $user = $request->user();
        $owner = $this->dashboardOwner($request, $user) ?? $user;

        $scope = (string) $request->input('scope', 'all');
        $base = $owner->apiSources();

        if (str_starts_with($scope, 'site:')) {
            return $base->where('site_id', (int) substr($scope, 5))->get();
        }
        if (str_starts_with($scope, 'source:')) {
            return $base->whereKey((int) substr($scope, 7))->get();
        }

        return $base->get(); // "all"
    }

    /**
     * If a dashboard_id is supplied and the user can read it, return its owner
     * so insights pull from the owner's sources (this is how view-only users
     * see data on dashboards an admin assigned them).
     */
    private function dashboardOwner(Request $request, User $user): ?User
    {
        $id = $request->input('dashboard_id');
        if (! $id) {
            return null;
        }

        $dashboard = Dashboard::find($id);
        if (! $dashboard) {
            return null;
        }

        if ($dashboard->user_id === $user->id) {
            return $user;
        }

        if (! $dashboard->assignees()->whereKey($user->id)->exists()) {
            abort(403);
        }

        return $dashboard->user;
    }

    private function snapshotIds(Collection $sources): array
    {
        return $sources->pluck('latest_snapshot_id')->filter()->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $summaries
     * @return array<string, mixed>
     */
    private function mergeSummaries(array $summaries): array
    {
        $scalars = ['total', 'online', 'stale', 'offline', 'compliant', 'non_compliant'];
        $maps = ['by_os', 'by_health', 'by_compliance', 'by_agent_version'];

        $out = array_fill_keys($scalars, 0);
        foreach ($maps as $m) {
            $out[$m] = [];
        }

        foreach ($summaries as $s) {
            foreach ($scalars as $k) {
                $out[$k] += $s[$k] ?? 0;
            }
            foreach ($maps as $m) {
                foreach (($s[$m] ?? []) as $label => $count) {
                    $out[$m][$label] = ($out[$m][$label] ?? 0) + $count;
                }
            }
        }

        foreach ($maps as $m) {
            arsort($out[$m]);
        }

        $out['compliance_pct'] = $out['total'] > 0 ? round($out['compliant'] / $out['total'] * 100, 1) : 0;
        $out['agent_versions'] = count($out['by_agent_version']);

        return $out;
    }
}
