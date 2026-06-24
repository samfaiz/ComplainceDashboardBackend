<?php

namespace App\Services\Connectors;

use App\Models\ApiSource;

/**
 * CrowdStrike Falcon uses a two-step pattern: query device IDs (with a scroll
 * cursor), then POST those IDs to the entities endpoint to get full records.
 * Auth is OAuth2 client-credentials (client_id in auth_config, secret = client_secret).
 */
class CrowdstrikeConnector extends AbstractConnector
{
    public function fetch(ApiSource $source, string $secret): array
    {
        $rc = $source->request_config ?? [];
        $queryPath = $rc['query_path'] ?? '/devices/queries/devices-scroll/v1';
        $entitiesPath = $rc['entities_path'] ?? '/devices/entities/devices/v2';
        $limit = (int) ($rc['limit'] ?? 1000);
        $maxPages = (int) ($rc['max_pages'] ?? 50);

        $token = $this->fetchOAuthToken($source, $secret);
        $client = $this->http($source)->withToken($token);

        $ids = [];
        $offset = null;
        $page = 0;

        do {
            $params = array_filter([
                'limit' => $limit,
                'offset' => $offset,
            ], fn ($v) => $v !== null);

            $response = $client->get($queryPath, $params);

            if ($response->failed()) {
                throw new ConnectorException(
                    'Falcon device query failed (HTTP '.$response->status().'): '.$this->trimBody($response->body())
                );
            }

            $json = $response->json();
            $batch = (array) data_get($json, 'resources', []);
            $ids = array_merge($ids, $batch);
            $offset = data_get($json, 'meta.pagination.offset');
            $page++;
        } while (! empty($batch) && ! empty($offset) && $page < $maxPages);

        if (empty($ids)) {
            return [];
        }

        // Hydrate IDs into full device records, batching to stay within limits.
        $records = [];
        foreach (array_chunk($ids, 100) as $chunk) {
            $response = $client->post($entitiesPath, ['ids' => $chunk]);

            if ($response->failed()) {
                throw new ConnectorException(
                    'Falcon device details failed (HTTP '.$response->status().'): '.$this->trimBody($response->body())
                );
            }

            $records = array_merge($records, (array) data_get($response->json(), 'resources', []));
        }

        return $records;
    }
}
