<?php

namespace App\Services\Connectors;

use App\Models\ApiSource;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

abstract class AbstractConnector implements Connector
{
    protected int $timeout = 30;

    /** Base HTTP client pointed at the source, honouring TLS-verify config. */
    protected function http(ApiSource $source): PendingRequest
    {
        $client = Http::baseUrl(rtrim($source->base_url, '/'))
            ->timeout($this->timeout)
            ->acceptJson();

        if (data_get($source->request_config, 'verify_tls', true) === false) {
            $client = $client->withoutVerifying();
        }

        return $client;
    }

    /**
     * Resolve authentication into request headers, performing a token
     * exchange first when the source uses OAuth2 client-credentials.
     *
     * @return array<string, string>
     */
    protected function authHeaders(ApiSource $source, string $secret): array
    {
        $cfg = $source->auth_config ?? [];

        return match ($source->auth_type) {
            ApiSource::AUTH_BEARER => [
                'Authorization' => trim(($cfg['token_prefix'] ?? 'Bearer').' '.$secret),
            ],
            ApiSource::AUTH_API_KEY_HEADER => [
                ($cfg['header'] ?? 'X-API-Key') => ($cfg['value_prefix'] ?? '') !== ''
                    ? $cfg['value_prefix'].' '.$secret
                    : $secret,
            ],
            ApiSource::AUTH_BASIC => [
                'Authorization' => 'Basic '.base64_encode(($cfg['username'] ?? '').':'.$secret),
            ],
            ApiSource::AUTH_OAUTH2_CC => [
                'Authorization' => 'Bearer '.$this->fetchOAuthToken($source, $secret),
            ],
            default => [],
        };
    }

    /** OAuth2 client-credentials token exchange (also covers MS Defender's resource param). */
    protected function fetchOAuthToken(ApiSource $source, string $secret): string
    {
        $cfg = $source->auth_config ?? [];
        $tokenUrl = $cfg['token_url'] ?? rtrim($source->base_url, '/').'/oauth2/token';

        // Substitute tenant placeholders (e.g. Microsoft Defender token endpoint).
        $tenant = $cfg['tenant_id'] ?? '';
        $tokenUrl = str_replace(['{tenant_id}', '{tenant}'], [$tenant, $tenant], $tokenUrl);

        $form = array_filter([
            'grant_type' => $cfg['grant_type'] ?? 'client_credentials',
            'client_id' => $cfg['client_id'] ?? null,
            'client_secret' => $secret,
            'scope' => $cfg['scope'] ?? null,
            'resource' => $cfg['resource'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $response = Http::asForm()->timeout($this->timeout)->post($tokenUrl, $form);

        if ($response->failed()) {
            throw new ConnectorException(
                'OAuth token request failed (HTTP '.$response->status().'): '.$this->trimBody($response->body())
            );
        }

        $token = data_get($response->json(), $cfg['token_path'] ?? 'access_token');

        if (! is_string($token) || $token === '') {
            throw new ConnectorException('OAuth token not found in response.');
        }

        return $token;
    }

    /**
     * Pull the array of records out of a decoded response body using the
     * configured data_path, falling back to common envelope keys.
     *
     * @param  array<string, mixed>|list<mixed>  $body
     * @return array<int, array<string, mixed>>
     */
    protected function extractRecords(array $body, ?string $dataPath): array
    {
        if ($dataPath !== null && $dataPath !== '') {
            $value = data_get($body, $dataPath);

            return is_array($value) ? array_values($value) : [];
        }

        if (array_is_list($body)) {
            return $body;
        }

        foreach (['data.affected_items', 'data', 'resources', 'value', 'results', 'items', 'agents'] as $key) {
            $value = data_get($body, $key);
            if (is_array($value)) {
                return array_values($value);
            }
        }

        return [];
    }

    protected function trimBody(string $body): string
    {
        return mb_substr($body, 0, 500);
    }
}
