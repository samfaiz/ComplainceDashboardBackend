<?php

namespace App\Support;

/**
 * The out-of-the-box dashboard every user gets before they customize. Widgets
 * are positioned on a 12-column grid (react-grid-layout). Each widget's
 * `config` tells the frontend which metric/field/series to render.
 */
class DefaultDashboard
{
    /** @return array<int, array<string, mixed>> */
    public static function layout(): array
    {
        return [
            ['id' => 'w-total', 'type' => 'stat', 'title' => 'Total Endpoints', 'config' => ['metric' => 'total', 'color' => 'slate'], 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 2],
            ['id' => 'w-online', 'type' => 'stat', 'title' => 'Online (24h)', 'config' => ['metric' => 'online', 'color' => 'green'], 'x' => 3, 'y' => 0, 'w' => 3, 'h' => 2],
            ['id' => 'w-offline', 'type' => 'stat', 'title' => 'Offline (>7d)', 'config' => ['metric' => 'offline', 'color' => 'red'], 'x' => 6, 'y' => 0, 'w' => 3, 'h' => 2],
            ['id' => 'w-compliance', 'type' => 'gauge', 'title' => 'Compliance %', 'config' => ['metric' => 'compliance_pct', 'color' => 'blue'], 'x' => 9, 'y' => 0, 'w' => 3, 'h' => 2],

            ['id' => 'w-os', 'type' => 'pie', 'title' => 'Endpoints by OS', 'config' => ['field' => 'os_platform'], 'x' => 0, 'y' => 2, 'w' => 4, 'h' => 4],
            ['id' => 'w-health', 'type' => 'donut', 'title' => 'Health Status', 'config' => ['field' => 'health_status'], 'x' => 4, 'y' => 2, 'w' => 4, 'h' => 4],
            ['id' => 'w-comp', 'type' => 'bar', 'title' => 'Compliance Breakdown', 'config' => ['field' => 'compliance_status'], 'x' => 8, 'y' => 2, 'w' => 4, 'h' => 4],

            ['id' => 'w-agent', 'type' => 'bar', 'title' => 'Agent / Sensor Version', 'config' => ['field' => 'agent_version', 'horizontal' => true], 'x' => 0, 'y' => 6, 'w' => 6, 'h' => 4],
            ['id' => 'w-trend', 'type' => 'line', 'title' => 'Compliance Trend', 'config' => ['series' => ['compliance_pct']], 'x' => 6, 'y' => 6, 'w' => 6, 'h' => 4],

            ['id' => 'w-table', 'type' => 'table', 'title' => 'Endpoints', 'config' => [], 'x' => 0, 'y' => 10, 'w' => 12, 'h' => 6],
        ];
    }
}
