<?php

namespace App\Services\Connectors;

/**
 * Ready-made connection profiles for the supported EDR/XDR/SIEM platforms.
 *
 * The setup wizard reads these to pre-fill the base URL, auth type, request
 * shape and field mappings, and to render the right input fields. The generic
 * profile leaves everything open for fully custom REST sources.
 *
 * `fields` describe the inputs to render. Each field's `target` says where the
 * value goes: "secret" (the encrypted API key), "base_url", or "auth.<key>".
 */
class VendorPresets
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            'generic' => [
                'vendor' => 'generic',
                'label' => 'Generic REST API',
                'description' => 'Connect to any EDR/XDR/SIEM that returns a JSON list of devices. You map the fields yourself.',
                'base_url' => '',
                'auth_type' => 'bearer',
                'auth_config' => [],
                'request_config' => [
                    'method' => 'GET',
                    'path' => '/',
                    'data_path' => null,
                    'pagination' => ['type' => 'none', 'limit' => 100, 'max_pages' => 50],
                ],
                'field_mappings' => [
                    'external_id' => '', 'hostname' => '', 'os_platform' => '',
                    'os_version' => '', 'agent_version' => '', 'health_status' => '',
                    'last_seen_at' => '', 'ip_address' => '', 'mac_address' => '',
                ],
                'fields' => [
                    ['key' => 'base_url', 'label' => 'API Base URL', 'type' => 'text', 'target' => 'base_url', 'required' => true, 'placeholder' => 'https://api.example.com'],
                    ['key' => 'secret', 'label' => 'API Key / Token', 'type' => 'password', 'target' => 'secret', 'required' => true],
                ],
                'docs' => 'Set the request path, the JSON data path to the device array, and map each field after the connection test.',
                'docs_url' => '',
                'setup_guide' => [
                    'Open your EDR/XDR/SIEM admin console and find its REST API documentation.',
                    'Locate the endpoint that lists devices/agents, and note the URL path plus the JSON property that holds the array.',
                    'Create an API key or token (usually under Settings → API / Integrations) with read-only access.',
                    'Enter the base URL and token here, run Test connection, then map the fields on the next step.',
                ],
            ],

            'crowdstrike' => [
                'vendor' => 'crowdstrike',
                'label' => 'CrowdStrike Falcon',
                'description' => 'OAuth2 client-credentials. Queries device IDs then hydrates full host records.',
                'base_url' => 'https://api.crowdstrike.com',
                'auth_type' => 'oauth2_client_credentials',
                'auth_config' => [
                    'token_url' => 'https://api.crowdstrike.com/oauth2/token',
                    'client_id' => '',
                ],
                'request_config' => [
                    'query_path' => '/devices/queries/devices-scroll/v1',
                    'entities_path' => '/devices/entities/devices/v2',
                    'limit' => 1000,
                    'max_pages' => 50,
                ],
                'field_mappings' => [
                    'external_id' => 'device_id',
                    'hostname' => 'hostname',
                    'os_platform' => 'platform_name',
                    'os_version' => 'os_version',
                    'agent_version' => 'agent_version',
                    'health_status' => 'status',
                    'last_seen_at' => 'last_seen',
                    'ip_address' => 'local_ip',
                    'mac_address' => 'mac_address',
                ],
                'fields' => [
                    ['key' => 'base_url', 'label' => 'API Base URL (cloud region)', 'type' => 'text', 'target' => 'base_url', 'required' => true, 'placeholder' => 'https://api.crowdstrike.com', 'help' => 'Use api.us-2.crowdstrike.com / api.eu-1.crowdstrike.com for other clouds.'],
                    ['key' => 'client_id', 'label' => 'API Client ID', 'type' => 'text', 'target' => 'auth.client_id', 'required' => true],
                    ['key' => 'secret', 'label' => 'API Client Secret', 'type' => 'password', 'target' => 'secret', 'required' => true],
                ],
                'docs' => 'Create an API client in Falcon with the "Hosts: Read" scope.',
                'docs_url' => 'https://falcon.crowdstrike.com/documentation/page/a2a7fc0e/crowdstrike-oauth2-based-apis',
                'setup_guide' => [
                    'Sign in to the Falcon console as an administrator.',
                    'Go to Support and resources → API clients and keys.',
                    'Click "Create API client" and give it a name (e.g. "Compliance Dashboard").',
                    'Under API scopes, grant Hosts → Read, then save.',
                    'Copy the Client ID and Client Secret — the secret is shown only once.',
                    'Match the base URL to your cloud: US-1 api.crowdstrike.com, US-2 api.us-2.crowdstrike.com, EU-1 api.eu-1.crowdstrike.com.',
                ],
            ],

            'defender' => [
                'vendor' => 'defender',
                'label' => 'Microsoft Defender for Endpoint',
                'description' => 'Azure AD app (OAuth2) reading the WindowsDefenderATP machines API.',
                'base_url' => 'https://api.securitycenter.microsoft.com',
                'auth_type' => 'oauth2_client_credentials',
                'auth_config' => [
                    'token_url' => 'https://login.microsoftonline.com/{tenant_id}/oauth2/token',
                    'tenant_id' => '',
                    'client_id' => '',
                    'resource' => 'https://api.securitycenter.microsoft.com',
                ],
                'request_config' => [
                    'method' => 'GET',
                    'path' => '/api/machines',
                    'data_path' => 'value',
                    'pagination' => ['type' => 'none'],
                ],
                'field_mappings' => [
                    'external_id' => 'id',
                    'hostname' => 'computerDnsName',
                    'os_platform' => 'osPlatform',
                    'os_version' => 'osVersion',
                    'agent_version' => 'agentVersion',
                    'health_status' => 'healthStatus',
                    'last_seen_at' => 'lastSeen',
                    'ip_address' => 'lastIpAddress',
                    'mac_address' => '',
                ],
                'fields' => [
                    ['key' => 'tenant_id', 'label' => 'Azure Tenant ID', 'type' => 'text', 'target' => 'auth.tenant_id', 'required' => true],
                    ['key' => 'client_id', 'label' => 'Application (Client) ID', 'type' => 'text', 'target' => 'auth.client_id', 'required' => true],
                    ['key' => 'secret', 'label' => 'Client Secret', 'type' => 'password', 'target' => 'secret', 'required' => true],
                ],
                'docs' => 'Register an Azure AD app with the WindowsDefenderATP "Machine.Read.All" application permission.',
                'docs_url' => 'https://learn.microsoft.com/microsoft-365/security/defender-endpoint/exposed-apis-create-app-webapp',
                'setup_guide' => [
                    'In the Azure portal open Microsoft Entra ID → App registrations → New registration; name it and register.',
                    'On the Overview page copy the Application (client) ID and the Directory (tenant) ID.',
                    'Go to API permissions → Add a permission → APIs my organization uses → WindowsDefenderATP.',
                    'Choose Application permissions → Machine.Read.All → Add, then click "Grant admin consent".',
                    'Go to Certificates & secrets → New client secret → copy the secret Value (shown only once).',
                ],
            ],

            'sentinelone' => [
                'vendor' => 'sentinelone',
                'label' => 'SentinelOne',
                'description' => 'API token in the Authorization header reading the agents API.',
                'base_url' => 'https://your-instance.sentinelone.net',
                'auth_type' => 'api_key_header',
                'auth_config' => [
                    'header' => 'Authorization',
                    'value_prefix' => 'ApiToken',
                ],
                'request_config' => [
                    'method' => 'GET',
                    'path' => '/web/api/v2.1/agents',
                    'query' => ['limit' => 100],
                    'data_path' => 'data',
                    'pagination' => [
                        'type' => 'cursor',
                        'limit' => 100,
                        'cursor_param' => 'cursor',
                        'next_cursor_path' => 'pagination.nextCursor',
                        'max_pages' => 50,
                    ],
                ],
                'field_mappings' => [
                    'external_id' => 'id',
                    'hostname' => 'computerName',
                    'os_platform' => 'osType',
                    'os_version' => 'osName',
                    'agent_version' => 'agentVersion',
                    'health_status' => 'networkStatus',
                    'last_seen_at' => 'lastActiveDate',
                    'ip_address' => 'lastIpToMgmt',
                    'mac_address' => '',
                ],
                'fields' => [
                    ['key' => 'base_url', 'label' => 'Management Console URL', 'type' => 'text', 'target' => 'base_url', 'required' => true, 'placeholder' => 'https://your-instance.sentinelone.net'],
                    ['key' => 'secret', 'label' => 'API Token', 'type' => 'password', 'target' => 'secret', 'required' => true],
                ],
                'docs' => 'Generate a service-user API token in the SentinelOne console.',
                'docs_url' => '',
                'setup_guide' => [
                    'Sign in to your SentinelOne management console.',
                    'Recommended: Settings → Users → Service Users → Create Service User with Viewer scope (or use your own profile).',
                    'Open the user and choose Actions → API Token → Generate (or "My User" → "API Token" → Regenerate).',
                    'Copy the API token — it is shown only once.',
                    'Use your console URL as the base URL, e.g. https://yourtenant.sentinelone.net.',
                    'A full API reference (Swagger) is under the user menu → "API Doc" inside the console.',
                ],
            ],

            'wazuh' => [
                'vendor' => 'wazuh',
                'label' => 'Wazuh',
                'description' => 'Basic-auth token exchange against the Wazuh API, then reads the agents endpoint.',
                'base_url' => 'https://your-wazuh-host:55000',
                'auth_type' => 'basic',
                'auth_config' => [
                    'username' => 'wazuh',
                    'auth_path' => '/security/user/authenticate',
                ],
                'request_config' => [
                    'method' => 'GET',
                    'path' => '/agents',
                    'limit' => 500,
                    'max_pages' => 50,
                    'verify_tls' => false,
                ],
                'field_mappings' => [
                    'external_id' => 'id',
                    'hostname' => 'name',
                    'os_platform' => 'os.platform',
                    'os_version' => 'os.version',
                    'agent_version' => 'version',
                    'health_status' => 'status',
                    'last_seen_at' => 'lastKeepAlive',
                    'ip_address' => 'ip',
                    'mac_address' => '',
                ],
                'fields' => [
                    ['key' => 'base_url', 'label' => 'Wazuh API URL', 'type' => 'text', 'target' => 'base_url', 'required' => true, 'placeholder' => 'https://your-wazuh-host:55000'],
                    ['key' => 'username', 'label' => 'API Username', 'type' => 'text', 'target' => 'auth.username', 'required' => true, 'placeholder' => 'wazuh'],
                    ['key' => 'secret', 'label' => 'API Password', 'type' => 'password', 'target' => 'secret', 'required' => true],
                ],
                'docs' => 'Self-signed Wazuh certificates are accepted by default (TLS verification off for this preset).',
                'docs_url' => 'https://documentation.wazuh.com/current/user-manual/api/getting-started.html',
                'setup_guide' => [
                    'The Wazuh API runs on the Wazuh manager over TCP port 55000.',
                    'Use an API account — the default is user "wazuh"; create scoped users under Wazuh → Security → Users if needed.',
                    'Set the base URL to https://your-manager-host:55000.',
                    'Self-signed certificates are accepted by this preset (TLS verification is disabled).',
                ],
            ],

            'trendmicro' => [
                'vendor' => 'trendmicro',
                'label' => 'Trend Micro Vision One',
                'description' => 'Trend Vision One XDR — Bearer API token reading the Endpoint Inventory (eiqs) API.',
                'base_url' => 'https://api.xdr.trendmicro.com',
                'auth_type' => 'bearer',
                'auth_config' => [],
                'request_config' => [
                    'method' => 'GET',
                    'path' => '/v3.0/eiqs/endpoints',
                    'query' => ['top' => 1000],
                    'data_path' => 'items',
                    'pagination' => ['type' => 'none'],
                ],
                'field_mappings' => [
                    'external_id' => 'agentGuid',
                    'hostname' => 'endpointName',
                    'os_platform' => 'osName',
                    'os_version' => 'osVersion',
                    'agent_version' => 'eppAgent.version',
                    'health_status' => 'eppAgent.connectionStatus',
                    'last_seen_at' => 'eppAgent.lastConnectedDateTime',
                    'ip_address' => 'ip.0',
                    'mac_address' => 'macAddress.0',
                ],
                'fields' => [
                    ['key' => 'base_url', 'label' => 'Vision One API URL (region)', 'type' => 'text', 'target' => 'base_url', 'required' => true, 'placeholder' => 'https://api.xdr.trendmicro.com', 'help' => 'Use api.eu / api.in / api.au / api.sg / api.jp.xdr.trendmicro.com for other regions.'],
                    ['key' => 'secret', 'label' => 'Authentication Token', 'type' => 'password', 'target' => 'secret', 'required' => true],
                ],
                'docs' => 'Create an API key in Vision One with endpoint inventory permission. The inventory schema is nested — verify the field mappings after testing.',
                'docs_url' => 'https://automation.trendmicro.com/xdr/home',
                'setup_guide' => [
                    'Sign in to Trend Vision One.',
                    'Go to Administration → API Keys.',
                    'Click Add API key and assign a role that includes endpoint/agent inventory read access.',
                    'Copy the Authentication token that is generated.',
                    'Pick the base URL for your region: US api.xdr.trendmicro.com, EU api.eu.xdr.trendmicro.com, JP api.xdr.trendmicro.co.jp, AU/SG/IN variants.',
                ],
            ],

            'cortex' => [
                'vendor' => 'cortex',
                'label' => 'Palo Alto Cortex XDR',
                'description' => 'Standard API auth (key + key-ID headers) reading the endpoints API.',
                'base_url' => 'https://api-yourfqdn.xdr.us.paloaltonetworks.com',
                'auth_type' => 'api_key_header',
                'auth_config' => ['header' => 'Authorization'],
                'request_config' => [
                    'method' => 'POST',
                    'path' => '/public_api/v1/endpoints/get_endpoint/',
                    'body' => ['request_data' => ['search_from' => 0, 'search_to' => 1000]],
                    'data_path' => 'reply.endpoints',
                    'headers' => [],
                    'pagination' => ['type' => 'none'],
                ],
                'field_mappings' => [
                    'external_id' => 'endpoint_id',
                    'hostname' => 'endpoint_name',
                    'os_platform' => 'os_type',
                    'os_version' => 'os_version',
                    'agent_version' => 'endpoint_version',
                    'health_status' => 'endpoint_status',
                    'last_seen_at' => 'last_seen',
                    'ip_address' => 'ip.0',
                    'mac_address' => 'mac_address.0',
                ],
                'fields' => [
                    ['key' => 'base_url', 'label' => 'API FQDN', 'type' => 'text', 'target' => 'base_url', 'required' => true, 'placeholder' => 'https://api-<fqdn>.xdr.<region>.paloaltonetworks.com', 'help' => 'Copy your tenant API FQDN from Cortex XDR → Settings → API Keys.'],
                    ['key' => 'auth_id', 'label' => 'API Key ID', 'type' => 'text', 'target' => 'header.x-xdr-auth-id', 'required' => true],
                    ['key' => 'secret', 'label' => 'API Key (Standard)', 'type' => 'password', 'target' => 'secret', 'required' => true],
                ],
                'docs' => 'Use a "Standard" security-level API key. Advanced keys (nonce/timestamp/hash) are not supported by this preset.',
                'docs_url' => 'https://docs-cortex.paloaltonetworks.com/r/Cortex-XDR/Cortex-XDR-API-Reference',
                'setup_guide' => [
                    'In Cortex XDR open Settings (gear) → Configurations → Integrations → API Keys.',
                    'Click "New Key", choose security level "Standard", pick a role such as Viewer, and generate.',
                    'Copy the API Key, and note its API Key ID (the number shown in the table).',
                    'Click "Copy API URL" to get your tenant FQDN base URL (https://api-<fqdn>.xdr.<region>.paloaltonetworks.com).',
                ],
            ],

            'cisco_amp' => [
                'vendor' => 'cisco_amp',
                'label' => 'Cisco Secure Endpoint (AMP)',
                'description' => 'Basic-auth (API client ID + key) reading the Secure Endpoint computers API.',
                'base_url' => 'https://api.amp.cisco.com',
                'auth_type' => 'basic',
                'auth_config' => ['username' => ''],
                'request_config' => [
                    'method' => 'GET',
                    'path' => '/v1/computers',
                    'data_path' => 'data',
                    'pagination' => ['type' => 'offset', 'limit' => 500, 'limit_param' => 'limit', 'offset_param' => 'offset', 'max_pages' => 50],
                ],
                'field_mappings' => [
                    'external_id' => 'connector_guid',
                    'hostname' => 'hostname',
                    'os_platform' => 'operating_system',
                    'os_version' => 'os_version',
                    'agent_version' => 'connector_version',
                    'health_status' => 'active',
                    'last_seen_at' => 'last_seen',
                    'ip_address' => 'internal_ips.0',
                    'mac_address' => 'network_addresses.0.mac',
                ],
                'fields' => [
                    ['key' => 'base_url', 'label' => 'API Host (region)', 'type' => 'text', 'target' => 'base_url', 'required' => true, 'placeholder' => 'https://api.amp.cisco.com', 'help' => 'EU: api.eu.amp.cisco.com · APJC: api.apjc.amp.cisco.com'],
                    ['key' => 'username', 'label' => '3rd-Party API Client ID', 'type' => 'text', 'target' => 'auth.username', 'required' => true],
                    ['key' => 'secret', 'label' => 'API Key', 'type' => 'password', 'target' => 'secret', 'required' => true],
                ],
                'docs' => 'Generate an API credential in the Secure Endpoint console under Accounts → API Credentials.',
                'docs_url' => 'https://api-docs.amp.cisco.com/',
                'setup_guide' => [
                    'Sign in to the Cisco Secure Endpoint console.',
                    'Go to Accounts → API Credentials → New API Credential.',
                    'Name it and set the scope to Read-only, then create it.',
                    'Copy the 3rd Party API Client ID and the API Key.',
                    'Choose the regional base URL: NAM api.amp.cisco.com, EU api.eu.amp.cisco.com, APJC api.apjc.amp.cisco.com.',
                ],
            ],

            'elastic' => [
                'vendor' => 'elastic',
                'label' => 'Elastic Security (Fleet)',
                'description' => 'Kibana Fleet API key reading enrolled Elastic Agents.',
                'base_url' => 'https://your-kibana:5601',
                'auth_type' => 'api_key_header',
                'auth_config' => ['header' => 'Authorization', 'value_prefix' => 'ApiKey'],
                'request_config' => [
                    'method' => 'GET',
                    'path' => '/api/fleet/agents',
                    'query' => ['perPage' => 200],
                    'data_path' => 'items',
                    'headers' => ['kbn-xsrf' => 'true'],
                    'pagination' => ['type' => 'page', 'limit' => 200, 'limit_param' => 'perPage', 'page_param' => 'page', 'start_page' => 1, 'max_pages' => 50],
                ],
                'field_mappings' => [
                    'external_id' => 'id',
                    'hostname' => 'local_metadata.host.hostname',
                    'os_platform' => 'local_metadata.os.platform',
                    'os_version' => 'local_metadata.os.version',
                    'agent_version' => 'local_metadata.elastic.agent.version',
                    'health_status' => 'status',
                    'last_seen_at' => 'last_checkin',
                    'ip_address' => 'local_metadata.host.ip.0',
                    'mac_address' => 'local_metadata.host.mac.0',
                ],
                'fields' => [
                    ['key' => 'base_url', 'label' => 'Kibana URL', 'type' => 'text', 'target' => 'base_url', 'required' => true, 'placeholder' => 'https://your-kibana:5601'],
                    ['key' => 'secret', 'label' => 'Fleet API Key (base64)', 'type' => 'password', 'target' => 'secret', 'required' => true, 'help' => 'Create under Kibana → Stack Management → API keys.'],
                ],
                'docs' => 'Elastic Agent details are nested under local_metadata — adjust mappings if your fields differ.',
                'docs_url' => 'https://www.elastic.co/guide/en/fleet/current/fleet-api-docs.html',
                'setup_guide' => [
                    'Open Kibana → Stack Management → Security → API keys → Create API key.',
                    'Give the owning user/role Fleet read access (for example the fleet read privileges).',
                    'Copy the Base64 "encoded" value of the key (not the separate id / api_key pair).',
                    'Use your Kibana URL as the base URL, e.g. https://your-kibana:5601.',
                ],
            ],

            'sophos' => [
                'vendor' => 'sophos',
                'label' => 'Sophos Central',
                'description' => 'OAuth2 client-credentials + tenant header reading the endpoints API.',
                'base_url' => 'https://api-us01.central.sophos.com',
                'auth_type' => 'oauth2_client_credentials',
                'auth_config' => [
                    'token_url' => 'https://id.sophos.com/api/v2/oauth2/token',
                    'client_id' => '',
                    'scope' => 'token',
                ],
                'request_config' => [
                    'method' => 'GET',
                    'path' => '/endpoint/v1/endpoints',
                    'query' => ['pageSize' => 500],
                    'data_path' => 'items',
                    'headers' => [],
                    'pagination' => ['type' => 'cursor', 'cursor_param' => 'pageFromKey', 'next_cursor_path' => 'pages.nextKey', 'max_pages' => 50],
                ],
                'field_mappings' => [
                    'external_id' => 'id',
                    'hostname' => 'hostname',
                    'os_platform' => 'os.platform',
                    'os_version' => 'os.name',
                    'agent_version' => 'health.agent.version',
                    'health_status' => 'health.overall',
                    'last_seen_at' => 'lastSeenAt',
                    'ip_address' => 'ipv4Addresses.0',
                    'mac_address' => 'macAddresses.0',
                ],
                'fields' => [
                    ['key' => 'base_url', 'label' => 'Regional Data Host', 'type' => 'text', 'target' => 'base_url', 'required' => true, 'placeholder' => 'https://api-us01.central.sophos.com', 'help' => 'Get your dataRegion host from the Sophos /whoami endpoint.'],
                    ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text', 'target' => 'auth.client_id', 'required' => true],
                    ['key' => 'tenant_id', 'label' => 'Tenant ID', 'type' => 'text', 'target' => 'header.X-Tenant-ID', 'required' => true],
                    ['key' => 'secret', 'label' => 'Client Secret', 'type' => 'password', 'target' => 'secret', 'required' => true],
                ],
                'docs' => 'Sophos requires a one-time /whoami call to discover your tenant ID and regional data host; enter those here.',
                'docs_url' => 'https://developer.sophos.com/getting-started',
                'setup_guide' => [
                    'In Sophos Central Admin go to Global Settings → API Credentials Management → Add Credential.',
                    'Choose a service-principal role (Read-only is fine) and save.',
                    'Click "Show client secret" and copy the Client ID and Client Secret.',
                    'Find your Tenant ID and data-region host: call GET https://api.central.sophos.com/whoami/v1 with a bearer token, then use the returned "id" (Tenant ID) and apiHosts.dataRegion (base URL).',
                ],
            ],
        ];
    }

    public static function get(string $vendor): ?array
    {
        return static::all()[$vendor] ?? null;
    }
}
