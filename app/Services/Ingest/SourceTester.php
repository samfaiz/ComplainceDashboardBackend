<?php

namespace App\Services\Ingest;

use App\Models\ApiSource;
use App\Services\Connectors\ConnectorFactory;
use Throwable;

/**
 * Performs a lightweight, capped pull used by the setup wizard to verify a
 * connection, surface available raw fields, and preview the normalized result
 * before the source is saved.
 */
class SourceTester
{
    public function __construct(
        private ConnectorFactory $connectors,
        private Normalizer $normalizer,
    ) {}

    /** @return array<string, mixed> */
    public function test(ApiSource $source, string $secret): array
    {
        // Cap the pull so a "test" never drags in the whole fleet.
        $rc = $source->request_config ?? [];
        $rc['pagination'] = array_merge(
            (array) ($rc['pagination'] ?? []),
            ['max_pages' => 1, 'limit' => 50]
        );
        $rc['limit'] = 50;
        $rc['max_pages'] = 1;
        $source->request_config = $rc;

        try {
            $records = $this->connectors->make($source)->fetch($source, $secret);
            $mappings = $source->field_mappings ?? [];

            $preview = [];
            foreach (array_slice($records, 0, 5) as $r) {
                if (! is_array($r)) {
                    continue;
                }
                $n = $this->normalizer->normalize($r, $mappings);
                unset($n['raw']); // keep the preview small
                $preview[] = $n;
            }

            return [
                'ok' => true,
                'count' => count($records),
                'available_fields' => $this->flattenKeys($records[0] ?? []),
                'sample_raw' => array_slice($records, 0, 2),
                'preview' => $preview,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Produce dot-notation leaf paths from a sample record so the mapping UI
     * can suggest which JSON paths are available.
     *
     * @param  array<string, mixed>  $record
     * @return array<int, string>
     */
    private function flattenKeys(array $record, string $prefix = '', int $depth = 0): array
    {
        $paths = [];

        foreach ($record as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value) && $depth < 2 && ! array_is_list($value)) {
                $paths = array_merge($paths, $this->flattenKeys($value, $path, $depth + 1));
            } else {
                $paths[] = $path;
            }
        }

        return $paths;
    }
}
