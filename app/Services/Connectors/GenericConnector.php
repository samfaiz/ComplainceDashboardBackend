<?php

namespace App\Services\Connectors;

use App\Models\ApiSource;

/**
 * Works with any REST API that returns a JSON list of devices/agents.
 * Drives Microsoft Defender (OAuth2), SentinelOne (API token header) and
 * fully user-configured generic sources. Supports none/offset/page/cursor
 * pagination.
 */
class GenericConnector extends AbstractConnector
{
    public function fetch(ApiSource $source, string $secret): array
    {
        $rc = $source->request_config ?? [];
        $method = strtoupper($rc['method'] ?? 'GET');
        $path = $rc['path'] ?? '/';
        $baseQuery = (array) ($rc['query'] ?? []);
        $extraHeaders = (array) ($rc['headers'] ?? []);
        $dataPath = $rc['data_path'] ?? null;
        $body = $rc['body'] ?? null;

        $pagination = (array) ($rc['pagination'] ?? ['type' => 'none']);
        $type = $pagination['type'] ?? 'none';
        $limit = (int) ($pagination['limit'] ?? 100);
        $maxPages = (int) ($pagination['max_pages'] ?? 50);

        $headers = array_merge($this->authHeaders($source, $secret), $extraHeaders);
        $client = $this->http($source)->withHeaders($headers);

        $records = [];
        $page = 0;
        $offset = (int) ($pagination['start'] ?? 0);
        $pageNumber = (int) ($pagination['start_page'] ?? 1);
        $cursor = null;

        do {
            $query = $baseQuery;

            if ($type === 'offset') {
                $query[$pagination['limit_param'] ?? 'limit'] = $limit;
                $query[$pagination['offset_param'] ?? 'offset'] = $offset;
            } elseif ($type === 'page') {
                $query[$pagination['limit_param'] ?? 'limit'] = $limit;
                $query[$pagination['page_param'] ?? 'page'] = $pageNumber;
            } elseif ($type === 'cursor' && $cursor !== null) {
                $query[$pagination['cursor_param'] ?? 'cursor'] = $cursor;
            }

            $response = $method === 'POST'
                ? $client->post($path, $body ?? $query)
                : $client->get($path, $query);

            if ($response->failed()) {
                throw new ConnectorException(
                    'Request failed (HTTP '.$response->status().'): '.$this->trimBody($response->body())
                );
            }

            $json = $response->json();

            if (! is_array($json)) {
                throw new ConnectorException('Response was not valid JSON.');
            }

            $batch = $this->extractRecords($json, $dataPath);
            $records = array_merge($records, $batch);
            $page++;

            // Determine whether to continue.
            if ($type === 'none' || $batch === []) {
                break;
            }

            if ($type === 'offset') {
                $offset += $limit;
                $continue = count($batch) >= $limit;
            } elseif ($type === 'page') {
                $pageNumber++;
                $continue = count($batch) >= $limit;
            } elseif ($type === 'cursor') {
                $cursor = data_get($json, $pagination['next_cursor_path'] ?? 'pagination.nextCursor');
                $continue = ! empty($cursor);
            } else {
                $continue = false;
            }
        } while ($continue && $page < $maxPages);

        return $records;
    }
}
