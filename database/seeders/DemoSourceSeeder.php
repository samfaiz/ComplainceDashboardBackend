<?php

namespace Database\Seeders;

use App\Models\ApiSource;
use App\Models\Endpoint;
use App\Models\Site;
use App\Models\User;
use App\Services\Connectors\VendorPresets;
use App\Services\Ingest\Summarizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DemoSourceSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@compliance.local')->first();
        if (! $admin) {
            return;
        }

        // Two demo sites, each with its own source, so "All sites" aggregates.
        $london = Site::updateOrCreate(['user_id' => $admin->id, 'name' => 'HQ — London']);
        $singapore = Site::updateOrCreate(['user_id' => $admin->id, 'name' => 'Branch — Singapore']);

        $this->seedSource($admin, $london, 'Demo EDR — London', 'LDN', 120);
        $this->seedSource($admin, $singapore, 'Demo EDR — Singapore', 'SGP', 80);
    }

    private function seedSource(User $admin, Site $site, string $name, string $tag, int $count): void
    {
        $preset = VendorPresets::get('generic');

        $source = ApiSource::updateOrCreate(
            ['user_id' => $admin->id, 'name' => $name],
            [
                'site_id' => $site->id,
                'vendor' => 'generic',
                'base_url' => 'https://demo-edr.local',
                'auth_type' => ApiSource::AUTH_BEARER,
                'auth_config' => [],
                'secret_mode' => ApiSource::SECRET_SAVED,
                'request_config' => ['method' => 'GET', 'path' => '/endpoints', 'data_path' => 'data'],
                'field_mappings' => $preset['field_mappings'],
                'refresh_interval_minutes' => 60,
                'is_enabled' => true,
                'last_status' => 'success',
            ]
        );
        $source->storeSecret('demo-secret-token');
        $source->save();

        $fleet = $this->buildFleet($count, $tag);
        $summarizer = new Summarizer();
        $lastSnapshotId = null;

        for ($day = 13; $day >= 0; $day--) {
            $capturedAt = now()->subDays($day)->setTime(8, 0);
            $normalized = [];
            foreach ($fleet as $host) {
                $normalized[] = $this->endpointForDay($host, $capturedAt, $day);
            }

            $snapshot = $source->snapshots()->create([
                'captured_at' => $capturedAt,
                'endpoint_count' => count($normalized),
                'summary' => $summarizer->summarize($normalized),
            ]);

            $rows = [];
            foreach ($normalized as $e) {
                $rows[] = [
                    'snapshot_id' => $snapshot->id,
                    'api_source_id' => $source->id,
                    'external_id' => $e['external_id'],
                    'hostname' => $e['hostname'],
                    'os_platform' => $e['os_platform'],
                    'os_version' => $e['os_version'],
                    'agent_version' => $e['agent_version'],
                    'health_status' => $e['health_status'],
                    'last_seen_at' => $e['last_seen_at']->format('Y-m-d H:i:s'),
                    'ip_address' => $e['ip_address'],
                    'mac_address' => $e['mac_address'],
                    'is_isolated' => $e['is_isolated'],
                    'compliance_status' => $e['compliance_status'],
                    'extra' => json_encode($e['extra']),
                    'raw' => json_encode($e['raw']),
                    'captured_at' => $capturedAt->format('Y-m-d H:i:s'),
                ];
            }
            Endpoint::insert($rows);
            $lastSnapshotId = $snapshot->id;
        }

        $source->forceFill([
            'latest_snapshot_id' => $lastSnapshotId,
            'last_run_at' => now(),
            'last_status' => 'success',
        ])->save();
    }

    /** @return array<int, array<string, mixed>> */
    private function buildFleet(int $count, string $tag): array
    {
        $platforms = [
            ['os' => 'Windows', 'weight' => 68, 'versions' => ['Windows 11 23H2', 'Windows 11 22H2', 'Windows 10 22H2', 'Windows Server 2022']],
            ['os' => 'macOS', 'weight' => 18, 'versions' => ['macOS 14.5 Sonoma', 'macOS 13.6 Ventura']],
            ['os' => 'Linux', 'weight' => 14, 'versions' => ['Ubuntu 22.04 LTS', 'RHEL 9.3', 'Debian 12']],
        ];
        $agentVersions = ['7.18.0', '7.18.0', '7.18.0', '7.16.2', '7.16.2', '7.10.4'];

        $fleet = [];
        for ($i = 1; $i <= $count; $i++) {
            $os = $this->weightedPick($platforms);
            $prefix = match ($os['os']) {
                'Windows' => 'WIN', 'macOS' => 'MAC', default => 'LNX',
            };

            $fleet[] = [
                'external_id' => sprintf('%s-dev-%05d', strtolower($tag), $i),
                'hostname' => sprintf('%s-%s-%03d', $prefix, $tag, $i),
                'os_platform' => $os['os'],
                'os_version' => $os['versions'][array_rand($os['versions'])],
                'agent_version' => $agentVersions[array_rand($agentVersions)],
                'ip_address' => '10.'.rand(0, 4).'.'.rand(0, 255).'.'.rand(2, 254),
                'mac_address' => implode(':', array_map(fn () => sprintf('%02X', rand(0, 255)), range(1, 6))),
                'reliability' => rand(0, 100), // determines how often it checks in
            ];
        }

        return $fleet;
    }

    /** @return array<string, mixed> */
    private function endpointForDay(array $host, Carbon $capturedAt, int $day): array
    {
        // Reliability + a little daily jitter decides the connectivity bucket.
        $score = $host['reliability'] - rand(0, 20) + ($day === 0 ? 5 : 0);

        if ($score > 25) {
            $lastSeen = $capturedAt->copy()->subMinutes(rand(1, 60 * 20)); // online (<24h)
            $health = 'online';
        } elseif ($score > 8) {
            $lastSeen = $capturedAt->copy()->subDays(rand(1, 6)); // stale (1-7d)
            $health = 'stale';
        } else {
            $lastSeen = $capturedAt->copy()->subDays(rand(8, 40)); // offline (>7d)
            $health = 'offline';
        }

        $hasAgent = $host['agent_version'] !== null;
        $compliance = in_array($health, ['offline'], true) || ! $hasAgent
            ? 'non_compliant'
            : ($health === 'online' || $health === 'stale' ? 'compliant' : 'unknown');

        return [
            'external_id' => $host['external_id'],
            'hostname' => $host['hostname'],
            'os_platform' => $host['os_platform'],
            'os_version' => $host['os_version'],
            'agent_version' => $host['agent_version'],
            'health_status' => $health,
            'last_seen_at' => $lastSeen,
            'ip_address' => $host['ip_address'],
            'mac_address' => $host['mac_address'],
            'is_isolated' => $health === 'offline' && rand(0, 9) === 0,
            'compliance_status' => $compliance,
            'extra' => ['vendor_status' => $health, 'site' => 'HQ'],
            'raw' => ['external_id' => $host['external_id'], 'hostname' => $host['hostname']],
        ];
    }

    private function weightedPick(array $items): array
    {
        $total = array_sum(array_column($items, 'weight'));
        $r = rand(1, $total);
        foreach ($items as $item) {
            $r -= $item['weight'];
            if ($r <= 0) {
                return $item;
            }
        }

        return $items[0];
    }
}
