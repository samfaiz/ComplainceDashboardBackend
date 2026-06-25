<?php

namespace App\Services\Security;

use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Builds a tech-stack inventory and attaches known CVE advisories.
 *
 * Sources:
 *  - PHP packages: composer.lock + `composer audit --format=json` (Packagist advisories DB).
 *  - npm packages: ../Frontend/package-lock.json + `npm audit --json` (GitHub Advisory DB).
 *
 * Audits hit external services and can take 10-30s, so results are cached.
 */
class TechStackAuditor
{
    private const CACHE_KEY = 'health:tech-stack';
    private const SEEN_KEY = 'health:tech-stack:seen-advisories';
    private const CACHE_TTL = 3600; // 1 hour
    private const PROCESS_TIMEOUT = 60;

    public function __construct(private ?NotificationService $notifications = null) {}

    public function snapshot(bool $forceRefresh = false): array
    {
        if ($forceRefresh) {
            Cache::forget(self::CACHE_KEY);
        }

        $snapshot = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $phpAdvisories = $this->composerAudit();
            $npmAdvisories = $this->npmAudit();

            $php = $this->phpPackages($phpAdvisories);
            $npm = $this->npmPackages($npmAdvisories);

            $packages = array_merge($php, $npm);
            usort($packages, fn ($a, $b) => $this->severityRank($b['highest_severity']) <=> $this->severityRank($a['highest_severity'])
                ?: strcmp($a['ecosystem'].$a['name'], $b['ecosystem'].$b['name']));

            return [
                'generated_at' => now()->toIso8601String(),
                'runtime' => $this->runtime(),
                'totals' => [
                    'packages' => count($packages),
                    'php' => count($php),
                    'npm' => count($npm),
                    'vulnerable' => count(array_filter($packages, fn ($p) => ! empty($p['advisories']))),
                    'advisories' => array_sum(array_map(fn ($p) => count($p['advisories']), $packages)),
                ],
                'packages' => $packages,
                'errors' => array_values(array_filter([
                    $phpAdvisories['error'] ?? null ? ['source' => 'composer', 'message' => $phpAdvisories['error']] : null,
                    $npmAdvisories['error'] ?? null ? ['source' => 'npm', 'message' => $npmAdvisories['error']] : null,
                ])),
            ];
        });

        $this->notifyNewAdvisories($snapshot);

        return $snapshot;
    }

    /**
     * Fire vuln.new_advisory for advisories we haven't seen before. State is
     * keyed on package+version+identifier so re-discovering an existing one
     * doesn't re-spam admins.
     */
    private function notifyNewAdvisories(array $snapshot): void
    {
        if (! $this->notifications) {
            return;
        }

        $seen = Cache::get(self::SEEN_KEY, []);
        $isFirstScanEver = empty($seen);

        $current = [];
        $newOnes = [];

        foreach ($snapshot['packages'] as $pkg) {
            foreach (($pkg['advisories'] ?? []) as $adv) {
                $key = strtolower($pkg['ecosystem'].'|'.$pkg['name'].'|'.$pkg['version'].'|'.($adv['cve'] ?? $adv['id'] ?? $adv['title']));
                $current[$key] = true;
                if (! isset($seen[$key])) {
                    $newOnes[] = [$pkg, $adv];
                }
            }
        }

        // Persist seen-set without TTL so a quiet day doesn't replay yesterday's CVEs.
        Cache::forever(self::SEEN_KEY, $current);

        // On first-ever scan, just baseline — don't blast emails for everything.
        if ($isFirstScanEver) {
            return;
        }

        foreach ($newOnes as [$pkg, $adv]) {
            $this->notifications->dispatch('vuln.new_advisory', [
                'package' => $pkg['name'],
                'version' => $pkg['version'],
                'ecosystem' => $pkg['ecosystem'],
                'cve' => $adv['cve'] ?? $adv['id'] ?? 'unknown',
                'severity' => $adv['severity'] ?? 'unknown',
                'title' => $adv['title'] ?? 'Security advisory',
                'url' => $adv['url'] ?? '',
            ]);
        }
    }

    private function runtime(): array
    {
        return [
            ['name' => 'PHP', 'version' => PHP_VERSION, 'ecosystem' => 'runtime'],
            ['name' => 'Laravel', 'version' => app()->version(), 'ecosystem' => 'runtime'],
            ['name' => 'Node.js', 'version' => $this->detectVersion('node', ['--version']), 'ecosystem' => 'runtime'],
            ['name' => 'npm', 'version' => $this->detectVersion('npm', ['--version']), 'ecosystem' => 'runtime'],
            ['name' => 'Composer', 'version' => $this->detectVersion('composer', ['--version', '--no-ansi']), 'ecosystem' => 'runtime'],
            ['name' => 'Database', 'version' => $this->databaseVersion(), 'ecosystem' => 'runtime'],
        ];
    }

    private function detectVersion(string $bin, array $args): ?string
    {
        try {
            $p = new Process(array_merge([$bin], $args));
            $p->setTimeout(5);
            $p->run();
            if (! $p->isSuccessful()) {
                return null;
            }
            $out = trim($p->getOutput());
            // Strip leading 'v' (node prints v22.x), and pull a semver-ish chunk from composer's verbose line.
            if (preg_match('/(\d+\.\d+\.\d+(?:[\.\-][\w\.]+)?)/', $out, $m)) {
                return $m[1];
            }
            return $out !== '' ? $out : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function databaseVersion(): ?string
    {
        try {
            $pdo = \Illuminate\Support\Facades\DB::connection()->getPdo();
            $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
            $version = $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
            return $driver.' '.$version;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{advisories: array<string, array<int, array>>, error?: string|null}
     */
    private function composerAudit(): array
    {
        $base = base_path();
        $process = new Process(['composer', 'audit', '--format=json', '--no-interaction', '--locked'], $base);
        $process->setTimeout(self::PROCESS_TIMEOUT);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            return ['advisories' => [], 'error' => 'composer audit timed out'];
        } catch (Throwable $e) {
            return ['advisories' => [], 'error' => 'composer audit failed: '.$e->getMessage()];
        }

        // composer audit exits non-zero when advisories exist, so don't gate on exitCode.
        $json = $process->getOutput();
        $data = json_decode($json, true);
        if (! is_array($data)) {
            $err = trim($process->getErrorOutput()) ?: 'composer audit returned no JSON';
            return ['advisories' => [], 'error' => mb_substr($err, 0, 240)];
        }

        $byPackage = [];
        foreach (($data['advisories'] ?? []) as $pkg => $list) {
            foreach ($list as $adv) {
                $byPackage[strtolower($pkg)][] = [
                    'id' => $adv['advisoryId'] ?? null,
                    'cve' => $adv['cve'] ?? null,
                    'title' => $adv['title'] ?? 'Security advisory',
                    'severity' => strtolower($adv['severity'] ?? 'unknown'),
                    'affected_versions' => $adv['affectedVersions'] ?? null,
                    'cvss' => null,
                    'url' => $adv['link'] ?? $adv['sources'][0]['remoteId'] ?? null,
                ];
            }
        }

        return ['advisories' => $byPackage];
    }

    /**
     * @return array{advisories: array<string, array<int, array>>, error?: string|null}
     */
    private function npmAudit(): array
    {
        $frontend = $this->frontendPath();
        if (! is_dir($frontend)) {
            return ['advisories' => [], 'error' => 'frontend directory not found'];
        }

        $process = new Process(['npm', 'audit', '--json', '--omit=dev'], $frontend);
        $process->setTimeout(self::PROCESS_TIMEOUT);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return ['advisories' => [], 'error' => 'npm audit timed out'];
        } catch (Throwable $e) {
            return ['advisories' => [], 'error' => 'npm audit failed: '.$e->getMessage()];
        }

        $json = $process->getOutput();
        $data = json_decode($json, true);
        if (! is_array($data)) {
            // npm audit may return text errors; pull first 240 chars from stderr.
            $err = trim($process->getErrorOutput()) ?: 'npm audit returned no JSON';
            return ['advisories' => [], 'error' => mb_substr($err, 0, 240)];
        }

        $byPackage = [];
        foreach (($data['vulnerabilities'] ?? []) as $pkg => $info) {
            foreach (($info['via'] ?? []) as $via) {
                if (! is_array($via)) {
                    continue; // string entries are upstream package names, not advisories
                }
                $url = $via['url'] ?? null;
                $cves = $via['cve'] ?? null;
                $byPackage[strtolower($pkg)][] = [
                    'id' => $this->extractGhsaId($url) ?? ($via['source'] ?? null),
                    'cve' => is_array($cves) ? implode(', ', $cves) : ($cves ?: $this->extractGhsaId($url)),
                    'title' => $via['title'] ?? 'Security advisory',
                    'severity' => strtolower($via['severity'] ?? $info['severity'] ?? 'unknown'),
                    'affected_versions' => $via['range'] ?? $info['range'] ?? null,
                    'cvss' => $via['cvss']['score'] ?? null,
                    'url' => $url,
                ];
            }
        }

        return ['advisories' => $byPackage];
    }

    private function extractGhsaId(?string $url): ?string
    {
        if (! $url) return null;
        return preg_match('#(GHSA-[a-z0-9-]+)#i', $url, $m) ? strtoupper($m[1]) : null;
    }

    private function phpPackages(array $audit): array
    {
        $lock = $this->readJson(base_path('composer.lock'));
        if (! $lock) {
            return [];
        }

        $packages = [];
        foreach (['packages', 'packages-dev'] as $key) {
            foreach (($lock[$key] ?? []) as $pkg) {
                $name = $pkg['name'] ?? null;
                if (! $name) continue;
                $advisories = $audit['advisories'][strtolower($name)] ?? [];
                $packages[] = [
                    'ecosystem' => 'php',
                    'name' => $name,
                    'version' => ltrim($pkg['version'] ?? '', 'v'),
                    'dev' => $key === 'packages-dev',
                    'description' => mb_substr((string)($pkg['description'] ?? ''), 0, 140),
                    'homepage' => $pkg['homepage'] ?? null,
                    'advisories' => $advisories,
                    'highest_severity' => $this->highestSeverity($advisories),
                ];
            }
        }

        usort($packages, fn ($a, $b) => $this->severityRank($b['highest_severity']) <=> $this->severityRank($a['highest_severity'])
            ?: strcmp($a['name'], $b['name']));

        return $packages;
    }

    private function npmPackages(array $audit): array
    {
        $lock = $this->readJson($this->frontendPath().'/package-lock.json');
        if (! $lock) {
            return [];
        }

        $packages = [];
        foreach (($lock['packages'] ?? []) as $path => $info) {
            // The lockfile root entry has key "" — skip it.
            if ($path === '' || str_contains($path, 'node_modules/.')) continue;
            // Skip workspace / link entries.
            if (! str_starts_with($path, 'node_modules/')) continue;
            $name = $info['name'] ?? substr($path, strlen('node_modules/'));
            // For scoped packages the path is node_modules/@scope/name — preserve it.
            if (! isset($info['name']) && str_contains($name, '/')) {
                // already a/b style; OK
            }
            $advisories = $audit['advisories'][strtolower($name)] ?? [];
            $packages[] = [
                'ecosystem' => 'npm',
                'name' => $name,
                'version' => $info['version'] ?? '',
                'dev' => (bool)($info['dev'] ?? false),
                'description' => null,
                'homepage' => null,
                'advisories' => $advisories,
                'highest_severity' => $this->highestSeverity($advisories),
            ];
        }

        // De-dupe by name (lockfiles can have nested copies)
        $byName = [];
        foreach ($packages as $p) {
            $key = $p['name'].'|'.$p['version'];
            if (! isset($byName[$key]) || (! empty($p['advisories']) && empty($byName[$key]['advisories']))) {
                $byName[$key] = $p;
            }
        }
        $packages = array_values($byName);

        usort($packages, fn ($a, $b) => $this->severityRank($b['highest_severity']) <=> $this->severityRank($a['highest_severity'])
            ?: strcmp($a['name'], $b['name']));

        return $packages;
    }

    private function highestSeverity(array $advisories): ?string
    {
        if (empty($advisories)) {
            return null;
        }
        $sevs = array_map(fn ($a) => $a['severity'] ?? 'unknown', $advisories);
        usort($sevs, fn ($a, $b) => $this->severityRank($b) <=> $this->severityRank($a));
        return $sevs[0];
    }

    private function severityRank(?string $severity): int
    {
        return match ($severity) {
            'critical' => 4,
            'high' => 3,
            'moderate', 'medium' => 2,
            'low' => 1,
            default => 0,
        };
    }

    private function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function frontendPath(): string
    {
        // Backend lives at <root>/Backend; Frontend lives at <root>/Frontend.
        return realpath(base_path('../Frontend')) ?: base_path('../Frontend');
    }
}
