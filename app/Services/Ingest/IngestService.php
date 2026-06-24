<?php

namespace App\Services\Ingest;

use App\Models\ApiSource;
use App\Models\Endpoint;
use App\Models\SourceRun;
use App\Services\Connectors\ConnectorFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class IngestService
{
    public function __construct(
        private ConnectorFactory $connectors,
        private Normalizer $normalizer,
        private Summarizer $summarizer,
    ) {}

    /**
     * Pull the source, normalize records, persist a snapshot with endpoints
     * and rollup summary, and update the source's status. Never throws —
     * failures are recorded on the run + source.
     */
    public function run(ApiSource $source, string $secret, string $trigger = 'scheduled'): SourceRun
    {
        $startedAt = now();

        /** @var SourceRun $run */
        $run = $source->runs()->create([
            'status' => 'running',
            'trigger' => $trigger,
            'started_at' => $startedAt,
        ]);

        try {
            $records = $this->connectors->make($source)->fetch($source, $secret);
            $mappings = $source->field_mappings ?? [];

            $normalized = [];
            foreach ($records as $record) {
                if (is_array($record)) {
                    $normalized[] = $this->normalizer->normalize($record, $mappings);
                }
            }

            $summary = $this->summarizer->summarize($normalized);
            $capturedAt = now();

            DB::transaction(function () use ($source, $run, $normalized, $summary, $capturedAt) {
                $snapshot = $source->snapshots()->create([
                    'source_run_id' => $run->id,
                    'captured_at' => $capturedAt,
                    'endpoint_count' => count($normalized),
                    'summary' => $summary,
                ]);

                $this->storeEndpoints($snapshot->id, (int) $source->id, $normalized, $capturedAt);

                $source->forceFill([
                    'latest_snapshot_id' => $snapshot->id,
                    'last_run_at' => $capturedAt,
                    'last_status' => 'success',
                    'last_error' => null,
                ])->save();
            });

            $this->pruneEndpointDetail($source);

            $run->forceFill([
                'status' => 'success',
                'finished_at' => now(),
                'duration_ms' => (int) $startedAt->diffInMilliseconds(now()),
                'records_ingested' => count($normalized),
            ])->save();
        } catch (Throwable $e) {
            $run->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'duration_ms' => (int) $startedAt->diffInMilliseconds(now()),
                'error_message' => $e->getMessage(),
            ])->save();

            $source->forceFill([
                'last_run_at' => now(),
                'last_status' => 'failed',
                'last_error' => $e->getMessage(),
            ])->save();
        }

        return $run->refresh();
    }

    /**
     * @param  array<int, array<string, mixed>>  $normalized
     */
    private function storeEndpoints(int $snapshotId, int $sourceId, array $normalized, Carbon $capturedAt): void
    {
        $captured = $capturedAt->format('Y-m-d H:i:s');

        foreach (array_chunk($normalized, 500) as $chunk) {
            $rows = [];

            foreach ($chunk as $e) {
                $lastSeen = $e['last_seen_at'] ?? null;

                $rows[] = [
                    'snapshot_id' => $snapshotId,
                    'api_source_id' => $sourceId,
                    'external_id' => $e['external_id'] ?? null,
                    'hostname' => $e['hostname'] ?? null,
                    'os_platform' => $e['os_platform'] ?? null,
                    'os_version' => $e['os_version'] ?? null,
                    'agent_version' => $e['agent_version'] ?? null,
                    'health_status' => $e['health_status'] ?? null,
                    'last_seen_at' => $lastSeen ? $lastSeen->format('Y-m-d H:i:s') : null,
                    'ip_address' => $e['ip_address'] ?? null,
                    'mac_address' => $e['mac_address'] ?? null,
                    'is_isolated' => $e['is_isolated'] ?? null,
                    'compliance_status' => $e['compliance_status'] ?? null,
                    'extra' => ! empty($e['extra']) ? json_encode($e['extra']) : null,
                    'raw' => isset($e['raw']) ? json_encode($e['raw']) : null,
                    'captured_at' => $captured,
                ];
            }

            Endpoint::insert($rows);
        }
    }

    /**
     * Keep snapshot summaries forever (cheap, powers trends) but drop the
     * heavy per-endpoint detail for snapshots older than the retention window.
     */
    private function pruneEndpointDetail(ApiSource $source): void
    {
        $keep = (int) config('security.endpoint_retention_snapshots', 30);

        $oldSnapshotIds = $source->snapshots()
            ->orderByDesc('captured_at')
            ->skip($keep)
            ->take(1000)
            ->pluck('id');

        if ($oldSnapshotIds->isNotEmpty()) {
            Endpoint::whereIn('snapshot_id', $oldSnapshotIds)->delete();
        }
    }
}
