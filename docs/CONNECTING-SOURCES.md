# Connecting a data source — beginner's guide

This guide explains, for each supported platform, **where to get the API
credentials** and **what to paste into each field** of the setup wizard
(**API Sources → Add source**).

The same steps are also shown inline in the wizard (the "How to get these
credentials" panel on the **Connect** step).

> General flow for every vendor:
> 1. **Source** — pick the vendor tile.
> 2. **Connect** — fill the fields below, then click **Test connection**.
> 3. **Map fields** — presets are pre-filled; adjust if your tenant's JSON differs (the test shows the available fields).
> 4. **Schedule & key** — choose a refresh interval and whether to **save the key** (encrypted at rest, enables background refresh) or **require it every login** (held only in your session).

---

## CrowdStrike Falcon

**You need:** an OAuth2 API client (Client ID + Client Secret).

1. Sign in to the **Falcon console** as an administrator.
2. Go to **Support and resources → API clients and keys**.
3. Click **Create API client**, name it (e.g. *Compliance Dashboard*).
4. Under **API scopes**, grant **Hosts → Read**. Save.
5. Copy the **Client ID** and **Client Secret** (the secret is shown once).
6. Note your cloud region for the base URL.

| Wizard field | Value |
|---|---|
| API Base URL | `https://api.crowdstrike.com` (US‑1) · `https://api.us-2.crowdstrike.com` (US‑2) · `https://api.eu-1.crowdstrike.com` (EU‑1) |
| API Client ID | the Client ID |
| API Client Secret | the Client Secret |

Docs: <https://falcon.crowdstrike.com/documentation>

---

## Microsoft Defender for Endpoint

**You need:** an Azure AD (Entra) app registration (Tenant ID + Client ID + Client Secret) with the `Machine.Read.All` application permission.

1. Azure portal → **Microsoft Entra ID → App registrations → New registration**. Name it, register.
2. On **Overview**, copy the **Application (client) ID** and **Directory (tenant) ID**.
3. **API permissions → Add a permission → APIs my organization uses → WindowsDefenderATP**.
4. **Application permissions → Machine.Read.All → Add**, then **Grant admin consent**.
5. **Certificates & secrets → New client secret** → copy the secret **Value** (shown once).

| Wizard field | Value |
|---|---|
| Azure Tenant ID | Directory (tenant) ID |
| Application (Client) ID | Application (client) ID |
| Client Secret | the secret Value |

Docs: <https://learn.microsoft.com/microsoft-365/security/defender-endpoint/exposed-apis-create-app-webapp>

---

## SentinelOne

**You need:** an API token (a dedicated **Service User** token is recommended).

1. Sign in to your **SentinelOne management console**.
2. (Recommended) **Settings → Users → Service Users → Create Service User** with **Viewer** scope.
3. Generate the token: open the user → **Actions → API Token → Generate** (or **My User → API Token**).
4. Copy the token (shown once).

| Wizard field | Value |
|---|---|
| Management Console URL | `https://yourtenant.sentinelone.net` |
| API Token | the generated token |

In-console reference: user menu → **API Doc**.

---

## Trend Micro Vision One

**You need:** an Authentication token from Vision One.

1. Sign in to **Trend Vision One**.
2. Go to **Administration → API Keys**.
3. **Add API key**; assign a role with endpoint/agent inventory **read** access.
4. Copy the **Authentication token**.

| Wizard field | Value |
|---|---|
| Vision One API URL | `https://api.xdr.trendmicro.com` (US) · `https://api.eu.xdr.trendmicro.com` (EU) · `https://api.xdr.trendmicro.co.jp` (JP) · AU/SG/IN variants |
| Authentication Token | the token |

> The endpoint inventory schema is nested — after **Test connection**, double-check the field mappings.

Docs: <https://automation.trendmicro.com/xdr/home>

---

## Palo Alto Cortex XDR

**You need:** a **Standard** API key + its Key ID, and your tenant API URL.

1. Cortex XDR → **Settings (gear) → Configurations → Integrations → API Keys**.
2. **New Key** → security level **Standard** → role **Viewer** → generate.
3. Copy the **API Key** and note its **API Key ID** (the number in the table).
4. Click **Copy API URL** to get the tenant FQDN.

| Wizard field | Value |
|---|---|
| API FQDN | `https://api-<fqdn>.xdr.<region>.paloaltonetworks.com` |
| API Key ID | the Key ID number |
| API Key (Standard) | the API key |

> Advanced keys (nonce/timestamp/hash) are not supported by this preset — use a **Standard** key.

Docs: <https://docs-cortex.paloaltonetworks.com/r/Cortex-XDR/Cortex-XDR-API-Reference>

---

## Cisco Secure Endpoint (AMP)

**You need:** a 3rd‑party API credential (Client ID + API Key).

1. Sign in to the **Secure Endpoint console**.
2. **Accounts → API Credentials → New API Credential**.
3. Name it, set scope **Read‑only**, create.
4. Copy the **3rd Party API Client ID** and **API Key**.

| Wizard field | Value |
|---|---|
| API Host (region) | `https://api.amp.cisco.com` (NAM) · `https://api.eu.amp.cisco.com` (EU) · `https://api.apjc.amp.cisco.com` (APJC) |
| 3rd-Party API Client ID | the Client ID |
| API Key | the API Key |

Docs: <https://api-docs.amp.cisco.com/>

---

## Elastic Security (Fleet)

**You need:** a Kibana API key with Fleet read access.

1. Kibana → **Stack Management → Security → API keys → Create API key**.
2. Ensure the owning user/role has **Fleet** read privileges.
3. Copy the **Base64 "encoded"** value of the key (not the separate id / api_key pair).

| Wizard field | Value |
|---|---|
| Kibana URL | `https://your-kibana:5601` |
| Fleet API Key (base64) | the encoded key |

Docs: <https://www.elastic.co/guide/en/fleet/current/fleet-api-docs.html>

---

## Wazuh

**You need:** a Wazuh API user (the manager exposes the API on port 55000).

1. The Wazuh API runs on the **Wazuh manager**, TCP port **55000**.
2. Use an API account — default user is **`wazuh`**; create scoped users under **Wazuh → Security → Users** if needed.

| Wizard field | Value |
|---|---|
| Wazuh API URL | `https://your-manager-host:55000` |
| API Username | `wazuh` (or your API user) |
| API Password | the user's password |

> Self-signed certificates are accepted by this preset (TLS verification disabled).

Docs: <https://documentation.wazuh.com/current/user-manual/api/getting-started.html>

---

## Sophos Central

**You need:** API credentials (Client ID + Secret), plus your Tenant ID and regional data host.

1. **Sophos Central Admin → Global Settings → API Credentials Management → Add Credential**.
2. Choose a service-principal role (**Read‑only** is fine), save.
3. Click **Show client secret** and copy the **Client ID** and **Client Secret**.
4. Find your **Tenant ID** and **data region host**: call
   `GET https://api.central.sophos.com/whoami/v1` with a bearer token — use the
   returned `id` (Tenant ID) and `apiHosts.dataRegion` (base URL).

| Wizard field | Value |
|---|---|
| Regional Data Host | `https://api-us01.central.sophos.com` (your dataRegion) |
| Client ID | the Client ID |
| Tenant ID | the `id` from /whoami |
| Client Secret | the Client Secret |

Docs: <https://developer.sophos.com/getting-started>

---

## Generic REST API (any other tool)

Use this when your platform isn't listed above but exposes a JSON list of devices.

1. Open your tool's **API documentation** and find the endpoint that lists devices/agents.
2. Note the **URL path** and the **JSON property** that contains the array (the "data path").
3. Create a **read-only API key/token** (usually under Settings → API / Integrations).
4. In the wizard, set the **base URL**, **auth type**, **path**, and **data path**, then **Test connection**.
5. On the **Map fields** step, the test shows the available JSON paths — type the matching path next to each standard field (e.g. `host.name`, `agent.version`, `last_checkin`).

Supported auth types: **Bearer token**, **API key header** (custom header name + optional value prefix), **Basic auth**, **OAuth2 client-credentials** (token URL + client ID).
Supported pagination: none / offset / page / cursor.
