<?php

namespace App\Services\Connectors;

use App\Models\ApiSource;

/**
 * Wazuh authenticates via GET /security/user/authenticate using Basic auth
 * (username + secret), returning a short-lived JWT used as a Bearer token for
 * GET /agents (offset-paginated under data.affected_items).
 */
class WazuhConnector extends AbstractConnector
{
    public function fetch(ApiSource $source, string $secret): array
    {
        $cfg = $source->auth_config ?? [];
        $rc = $source->request_config ?? [];

        $username = $cfg['username'] ?? 'wazuh';
        $authPath = $cfg['auth_path'] ?? '/security/user/authenticate';
        $agentsPath = $rc['path'] ?? '/agents';
        $limit = (int) ($rc['limit'] ?? 500);
        $maxPages = (int) ($rc['max_pages'] ?? 50);

        $authResponse = $this->http($source)
            ->withBasicAuth($username, $secret)
            ->get($authPath);

        if ($authResponse->failed()) {
            throw new ConnectorException(
                'Wazuh authentication failed (HTTP '.$authResponse->status().'): '.$this->trimBody($authResponse->body())
            );
        }

        $token = data_get($authResponse->json(), 'data.token');

        if (! is_string($token) || $token === '') {
            throw new ConnectorException('Wazuh did not return an auth token.');
        }

        $client = $this->http($source)->withToken($token);

        $records = [];
        $offset = 0;
        $page = 0;

        do {
            $response = $client->get($agentsPath, ['limit' => $limit, 'offset' => $offset]);

            if ($response->failed()) {
                throw new ConnectorException(
                    'Wazuh agents request failed (HTTP '.$response->status().'): '.$this->trimBody($response->body())
                );
            }

            $batch = (array) data_get($response->json(), 'data.affected_items', []);
            $records = array_merge($records, $batch);
            $offset += $limit;
            $page++;
        } while (count($batch) >= $limit && $page < $maxPages);

        return $records;
    }
}
