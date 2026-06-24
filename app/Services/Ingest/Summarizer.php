<?php

namespace App\Services\Ingest;

/**
 * Pre-aggregates a set of normalized endpoints into the rollup stored on each
 * snapshot. These rollups power both the default dashboard cards and the
 * time-series trend charts without scanning the endpoints table.
 */
class Summarizer
{
    /**
     * @param  array<int, array<string, mixed>>  $endpoints
     * @return array<string, mixed>
     */
    public function summarize(array $endpoints): array
    {
        $total = count($endpoints);

        $byOs = [];
        $byHealth = ['online' => 0, 'stale' => 0, 'offline' => 0, 'error' => 0, 'unknown' => 0];
        $byCompliance = ['compliant' => 0, 'non_compliant' => 0, 'unknown' => 0];
        $byAgentVersion = [];

        foreach ($endpoints as $e) {
            $os = $e['os_platform'] ?: 'Unknown';
            $byOs[$os] = ($byOs[$os] ?? 0) + 1;

            $health = $e['health_status'] ?? 'unknown';
            $byHealth[$health] = ($byHealth[$health] ?? 0) + 1;

            $compliance = $e['compliance_status'] ?? 'unknown';
            $byCompliance[$compliance] = ($byCompliance[$compliance] ?? 0) + 1;

            $ver = $e['agent_version'] ?: 'Unknown';
            $byAgentVersion[$ver] = ($byAgentVersion[$ver] ?? 0) + 1;
        }

        arsort($byOs);
        arsort($byAgentVersion);

        $compliant = $byCompliance['compliant'] ?? 0;

        return [
            'total' => $total,
            'by_os' => $byOs,
            'by_health' => $byHealth,
            'by_compliance' => $byCompliance,
            'by_agent_version' => $byAgentVersion,
            'online' => $byHealth['online'] ?? 0,
            'stale' => $byHealth['stale'] ?? 0,
            'offline' => $byHealth['offline'] ?? 0,
            'compliant' => $compliant,
            'non_compliant' => $byCompliance['non_compliant'] ?? 0,
            'compliance_pct' => $total > 0 ? round($compliant / $total * 100, 1) : 0,
            'agent_versions' => count($byAgentVersion),
        ];
    }
}
