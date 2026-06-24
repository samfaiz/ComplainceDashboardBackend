<?php

namespace App\Services\Ingest;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns a raw vendor record into the normalized endpoint shape using the
 * source's field mappings, then derives connectivity + compliance posture in
 * a vendor-agnostic way.
 */
class Normalizer
{
    /** Standard normalized columns that have first-class storage. */
    public const STANDARD = [
        'external_id', 'hostname', 'os_platform', 'os_version', 'agent_version',
        'health_status', 'last_seen_at', 'ip_address', 'mac_address',
        'is_isolated', 'compliance_status',
    ];

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, string>  $mappings  normalized field => json path
     * @return array<string, mixed>
     */
    public function normalize(array $record, array $mappings): array
    {
        $out = [];
        $extra = [];

        foreach ($mappings as $field => $path) {
            if ($path === null || $path === '') {
                continue;
            }

            $value = data_get($record, $path);

            if (in_array($field, self::STANDARD, true)) {
                $out[$field] = $value;
            } else {
                $extra[$field] = $value; // custom mapped fields
            }
        }

        $out['os_platform'] = $this->normalizePlatform($out['os_platform'] ?? null);
        $out['last_seen_at'] = $this->parseTimestamp($out['last_seen_at'] ?? null);

        $vendorStatus = $out['health_status'] ?? null;
        $out['health_status'] = $this->deriveHealth($vendorStatus, $out['last_seen_at']);
        $out['compliance_status'] = $this->deriveCompliance(
            $out['health_status'],
            $out['agent_version'] ?? null
        );

        if ($vendorStatus !== null && $vendorStatus !== '') {
            $extra['vendor_status'] = (string) $vendorStatus;
        }

        // Coerce scalars to strings where the column expects one.
        foreach (['external_id', 'hostname', 'os_version', 'agent_version', 'ip_address', 'mac_address'] as $k) {
            if (isset($out[$k]) && ! is_scalar($out[$k])) {
                $out[$k] = is_array($out[$k]) ? ($out[$k][0] ?? null) : null;
            }
            if (isset($out[$k])) {
                $out[$k] = $out[$k] === null ? null : (string) $out[$k];
            }
        }

        $out['extra'] = $extra ?: null;
        $out['raw'] = $record;

        return $out;
    }

    private function normalizePlatform(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $v = strtolower($value);

        return match (true) {
            str_contains($v, 'win') => 'Windows',
            str_contains($v, 'mac') || str_contains($v, 'osx') || str_contains($v, 'darwin') => 'macOS',
            str_contains($v, 'lin') || str_contains($v, 'ubuntu') || str_contains($v, 'centos') || str_contains($v, 'rhel') || str_contains($v, 'debian') => 'Linux',
            str_contains($v, 'android') => 'Android',
            str_contains($v, 'ios') => 'iOS',
            default => ucfirst($value),
        };
    }

    private function parseTimestamp(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            // Numeric epoch (seconds or milliseconds).
            if (is_numeric($value)) {
                $n = (int) $value;
                if ($n > 9999999999) { // milliseconds
                    $n = intdiv($n, 1000);
                }

                return CarbonImmutable::createFromTimestamp($n);
            }

            return CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    /** Map vendor status + last-seen recency into a consistent connectivity bucket. */
    private function deriveHealth(?string $vendorStatus, ?CarbonImmutable $lastSeen): string
    {
        if ($lastSeen !== null) {
            $hours = $lastSeen->diffInHours(now());

            if ($hours <= 24) {
                return 'online';
            }
            if ($hours <= 24 * 7) {
                return 'stale';
            }

            return 'offline';
        }

        if ($vendorStatus !== null && $vendorStatus !== '') {
            $s = strtolower($vendorStatus);

            return match (true) {
                str_contains($s, 'active') || str_contains($s, 'connected') || $s === 'normal' || str_contains($s, 'online') => 'online',
                str_contains($s, 'disconnect') || str_contains($s, 'inactive') || str_contains($s, 'offline') || str_contains($s, 'never') => 'offline',
                str_contains($s, 'error') || str_contains($s, 'impaired') || str_contains($s, 'nosensor') => 'error',
                default => 'unknown',
            };
        }

        return 'unknown';
    }

    /**
     * Default compliance posture: an endpoint is compliant when its agent is
     * present and it has checked in recently. Tunable per-org policy can build
     * on top of this baseline.
     */
    private function deriveCompliance(string $health, ?string $agentVersion): string
    {
        $hasAgent = $agentVersion !== null && $agentVersion !== '';

        if (in_array($health, ['offline', 'error'], true) || ! $hasAgent) {
            return 'non_compliant';
        }

        if (in_array($health, ['online', 'stale'], true)) {
            return 'compliant';
        }

        return 'unknown';
    }
}
