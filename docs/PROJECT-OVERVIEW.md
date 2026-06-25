# EDR / XDR / AV Compliance Dashboard — Project Overview

A complete technical **and** logical summary of the system: what it does, how each
part is built, and the reasoning behind every major decision.

---

## 1. What it is

A security-operations web application that connects to your endpoint-security tools
(EDR / XDR / AV / SIEM), pulls their device inventories through their APIs, normalizes
everything into one schema, and presents a unified, historical, role-restricted view
of **endpoint compliance** — across every vendor and every site.

- **Backend:** Laravel 13 + MySQL — REST API, Sanctum SPA auth.
- **Frontend:** Next.js 16 (App Router) + Tailwind v4 + shadcn-style UI + Recharts.
- **Deployment:** two subdomains — API (`api…`) and dashboard (`…`) — behind nginx.

---

## 2. The problem, and the logic for solving it this way

**Problem.** Organizations run several endpoint tools across multiple sites. Each has
its own console, login, permission model, and data format. There is no single,
trustworthy answer to *"are all our endpoints protected, current, and checking in —
everywhere, right now?"* and no safe way to let junior staff, auditors, or clients
*see* posture without granting full security-console access.

**Logic of the solution.**
1. **Normalize** every vendor's records into one schema → comparison and totals become
   possible (you can't sum apples and oranges; normalize first).
2. **Snapshot** every pull → you get history and trends, not just "now".
3. **Scope** the data by site / source / everything → one dataset serves many views.
4. **Restrict by role** → monitoring (read-only) is separated from action (the vendor
   console), enabling least-privilege access for L1/auditors/clients.
5. **Encrypt the only real secret (the API key)** to a high standard → the tool earns
   the right to hold credentials.

Everything below is an implementation of those five logical pillars.

---

## 3. High-level architecture

```
                         ┌─────────────────────────────────────────┐
  Browser (SPA)          │  Frontend — Next.js (Node server)        │
  dashboard.<domain>  ───▶  • App Router pages, React Query         │
                         │  • Recharts widgets, dashboard builder    │
                         └───────────────┬──────────────────────────┘
                                         │  fetch (credentials: include)
                                         │  Sanctum cookie + X-XSRF-TOKEN
                                         ▼
                         ┌─────────────────────────────────────────┐
  api.<domain>           │  Backend — Laravel 13 (PHP-FPM)          │
                         │  Controllers → Services → Eloquent → MySQL│
                         │                                           │
   Scheduler (cron) ────▶│  sources:refresh-due (every minute)      │
                         └───────────────┬──────────────────────────┘
                                         │  HTTP (per vendor API)
                                         ▼
              CrowdStrike · Defender · SentinelOne · Trend Micro ·
              Cortex XDR · Cisco AMP · Elastic · Wazuh · Sophos · Generic
```

**Why a cookie-based SPA (not bearer tokens):** for a security tool, httpOnly session
cookies + CSRF are safer than storing tokens in JS (XSS can't read httpOnly cookies).
The two subdomains share cookies via `SESSION_DOMAIN=.<root-domain>`.

---

## 4. Tech stack (and why)

| Layer | Choice | Why |
|---|---|---|
| API | **Laravel 13** | Batteries-included (auth, validation, queue, scheduler, Eloquent); fast to build securely |
| DB | **MySQL** | Relational fit for snapshots/endpoints/audit; ubiquitous in hosting |
| Auth | **Sanctum (SPA cookies)** | First-party SPA session auth without JS-readable tokens |
| Hashing | **Argon2id** | OWASP-recommended, memory-hard password hashing |
| MFA | **TOTP (google2fa)** | Standard authenticator-app compatibility |
| Key crypto | **AES-256-GCM (OpenSSL)** | Authenticated encryption for connector secrets |
| UI | **Next.js 16 App Router** | Modern React, SSR server, file routing |
| Styling | **Tailwind v4 + shadcn-style** | Fast, consistent, dark security-console aesthetic |
| Charts | **Recharts** | Declarative, composable chart primitives |
| Data fetching | **TanStack Query** | Caching, invalidation, background refetch |
| Grid builder | **react-grid-layout** | Drag/resize dashboard widgets |

---

## 5. End-to-end data flow

```
Connect a source (wizard)         →  ApiSource row (+ encrypted/session secret)
        │
Scheduler or manual "Refresh"     →  IngestService.run(source, secret)
        │
ConnectorFactory.make(source)     →  Generic / CrowdStrike / Wazuh connector
        │   (auth resolve + paginate + data_path extract)
Raw vendor records                →  Normalizer (map fields, derive health/compliance)
        │
Normalized endpoints              →  Summarizer (rollup counts) + DB write:
        │                              • snapshots (captured_at, summary JSON)
        │                              • endpoints (one row per device)
        ▼
Frontend reads via InsightsController (scope-aware): summary / aggregate / trends /
data / evaluate  → Recharts widgets, tables, custom-rule stats.
```

**Logic:** ingestion and presentation are decoupled. Ingestion produces a durable,
normalized record (snapshot + endpoints + rollup). Presentation only ever reads that —
so the UI is fast, vendor-agnostic, and historical by construction.

---

## 6. Feature catalogue — *What · How (technical) · Why (logical)*

### 6.1 Authentication & MFA
- **What:** email/password login, optional TOTP 2FA with recovery codes.
- **How:** `AuthController` verifies credentials; if `mfa_enabled`, it stores a pending
  `mfa.user_id` in the session and returns `mfa_required`, then `/login/mfa` verifies a
  TOTP code (`MfaService` via google2fa) or a one-time recovery code before
  `Auth::login()` + session regeneration. Passwords hashed with **Argon2id**
  (`config/hashing.php`); auto-rehash on login if work factor changes.
- **Why:** two-step session establishment means a stolen password alone can't log in;
  recovery codes prevent lockout if the authenticator is lost.

### 6.2 Role-based access control (RBAC)
- **What:** three roles — **Admin / Analyst / Viewer**.
- **How:** `role` column + `EnsureRole` middleware (`role:admin`) + `User::canManage()`
  (admin/analyst) / `isAdmin()`. Viewers are read-only; analysts manage sources/
  dashboards; admins manage users.
- **Why:** least privilege — the people who *monitor* (Viewer) are separated from those
  who *configure* (Analyst/Admin). This is what lets L1/auditors/clients see posture
  without dangerous powers.

### 6.3 Login-IP security & audit
- **What:** capture login IP, baseline known IPs, **red-flag a new IP**, track active
  time, lock out brute force, full audit log.
- **How:** `LoginSecurityService` records `login_events`, maintains `known_ips` (first
  IP = trusted baseline; later new IP sets `ip_flagged`), increments
  `failed_login_attempts` and sets `locked_until` after N tries. `TrackActivity`
  middleware refreshes `last_seen_at`/`current_ip` (and flags mid-session IP changes).
  `AuditLogger` writes `audit_logs` for logins, source/dashboard/user changes.
- **Why:** account-takeover and insider misuse show up as anomalies (new IP, lockouts);
  the audit trail provides accountability and compliance evidence.

### 6.4 Connector engine (the heart of ingestion)
- **What:** connect to any EDR/XDR/SIEM REST API; **presets for 10 platforms** —
  Generic, CrowdStrike Falcon, Microsoft Defender, SentinelOne, Trend Micro Vision One,
  Palo Alto Cortex XDR, Cisco Secure Endpoint (AMP), Elastic Security, Wazuh, Sophos.
- **How:** `ConnectorFactory` picks a connector (`CrowdstrikeConnector` for the two-step
  query→entities flow, `WazuhConnector` for basic-auth token exchange, else
  `GenericConnector`). `AbstractConnector` resolves auth (Bearer / API-key header /
  Basic / OAuth2 client-credentials with tenant-placeholder substitution), extracts the
  device array via a configurable `data_path`, and paginates (none/offset/page/cursor).
  `VendorPresets` ships each platform's base URL, auth type, request shape, default
  field mappings, **plus a step-by-step "how to get the API key" guide and docs link**
  surfaced in the wizard.
- **Why:** one configurable engine + presets means broad coverage without one class per
  vendor, and the generic mapper future-proofs you for any tool not yet listed.

### 6.5 Secret encryption (the trust core)
- **What:** API keys are encrypted; two modes — **Save** (encrypted at rest) or
  **Require every login** (never stored).
- **How:** `SecretBox` does **AES-256-GCM** (authenticated; tamper-evident) with a
  dedicated `DATA_ENCRYPTION_KEY` separate from `APP_KEY` (independent rotation).
  Saved secrets live as ciphertext in `api_sources.secret_encrypted`; per-login secrets
  live only in the **encrypted session** (`SessionSecretVault`) and are wiped on logout.
- **Why:** the API key is the only true secret; protecting it to bank-grade and offering
  a never-stored option removes the "is the dashboard a new risk?" objection. (Trade-off:
  per-login mode disables background auto-refresh — honest and documented.)

### 6.6 Normalization
- **What:** turn each vendor's record into one shape (`hostname, os_platform,
  os_version, agent_version, health_status, last_seen_at, ip, mac, compliance_status,
  raw`).
- **How:** `Normalizer` reads `field_mappings` (dot-paths) from the source, normalizes
  OS names, parses timestamps (ISO or epoch), then **derives** connectivity from
  last-seen recency (≤24h online, ≤7d stale, else offline) and compliance from
  agent-present + recency. Original payload kept in `raw`.
- **Why:** consistent semantics across vendors is what makes a single compliance % and
  cross-tool charts meaningful.

### 6.7 Snapshots & trends
- **What:** every refresh is stored as a point-in-time snapshot with a pre-aggregated
  summary; trends are computed across snapshots.
- **How:** `IngestService` writes a `snapshots` row (`summary` JSON: by_os, by_health,
  by_compliance, by_agent_version, compliance_pct, online/stale/offline) + `endpoints`
  rows; old snapshots keep their (cheap) summary but their (heavy) per-endpoint detail
  is pruned past a retention window. `Summarizer` builds the rollups once at write time.
- **Why:** pre-aggregating at write makes trend/dashboard reads O(snapshots) instead of
  scanning millions of endpoint rows — fast charts, provable history.

### 6.8 Sites & scope aggregation
- **What:** group sources into **Sites**; view **All sites**, one site, or one source.
- **How:** `sites` table + `api_sources.site_id`; `InsightsController` resolves a
  `scope` string (`all | site:{id} | source:{id}`) to a set of sources, then **merges
  their latest-snapshot summaries** (summary endpoints are additive) for `summary`,
  unions their endpoints for `aggregate`/`data`, and date-buckets snapshots for
  `trends`. Frontend `useScope` + one-click site chips drive it.
- **Why:** multi-location/multi-tenant monitoring in a single pane; one ingestion effort
  produces per-site and estate-wide views.

### 6.9 Default dashboard
- **What:** an out-of-the-box dashboard (totals, online, offline, compliance gauge, OS
  pie, health donut, compliance bar, agent-version bar, trend line, endpoint table).
- **How:** `DefaultDashboard::layout()` defines a 12-column widget grid; auto-created
  per user on first load (`/dashboards/default`).
- **Why:** instant value with zero configuration; a sensible baseline users then tweak.

### 6.10 Custom dashboard builder
- **What:** drag/resize/add/remove widgets; choose chart type, metric, field, or series;
  saved **per user**.
- **How:** `/builder` uses react-grid-layout; widgets persist as JSON in `dashboards.
  layout`; `WidgetConfigDialog` configures each. Loads automatically every login.
- **Why:** different roles care about different things; saved layouts mean nobody
  reconfigures on each session.

### 6.11 Custom rule engine
- **What:** stat/gauge widgets that **count endpoints matching your own logic** — e.g.
  *last seen > 2 days*, *Windows AND non-compliant*, *agent version ≠ X*.
- **How:** the builder's RuleBuilder produces `{match: all|any, conditions:[{field, op,
  value}]}`; `POST /insights/evaluate` runs it through `RuleEvaluator`, which translates
  conditions into a scoped Eloquent query (special-casing `last_seen_days` as a time
  cutoff, supporting eq/neq/contains/in/empty, AND/OR). Returns count/total/percent.
- **Why:** vendor dashboards have fixed buckets; real policy ("offline = >2 days for us")
  must be expressible by the user, across all tools, on demand.

### 6.12 Endpoint data table
- **What:** raw normalized records with search, filters, sort, pagination, **CSV export**.
- **How:** `EndpointTable` calls `/insights/data` (scope-aware) with query params;
  exports the current view client-side.
- **Why:** charts answer "how many"; the table answers "which ones" — needed for action.

### 6.13 Admin CMS
- **What:** list users, create users, change role/status, **reset passwords** (issues a
  temporary password + forces change), clear IP flags, unlock accounts, reset MFA; view
  **login activity** and the **audit log**.
- **How:** `AdminController` (under `role:admin`), `/admin` page with tabs + a manage-user
  dialog showing the user's known IPs and recent sign-ins.
- **Why:** central, accountable user administration without DB access; supports
  least-privilege and incident response (lock/reset/flag).

### 6.14 System health
- **What:** live status of DB, cache, queue, scheduler heartbeat, sources (failing/stale),
  and security posture (MFA adoption, flagged IPs, locked accounts, online users).
- **How:** `HealthController` probes each subsystem; the scheduler writes a
  `scheduler.last_run` cache heartbeat so the UI can detect a dead cron.
- **Why:** a monitoring tool must monitor itself — silent ingestion failure is worse than
  a visible error.

### 6.15 Scheduling
- **What:** automatic refresh of each source on its own interval (1h/2h/…).
- **How:** `RefreshDueSources` command (`sources:refresh-due`) runs every minute (cron →
  `schedule:run`), finds enabled saved-secret sources whose interval elapsed, and runs
  `IngestService` inline. Writes the health heartbeat each tick.
- **Why:** keeps data fresh without manual pulls; inline (no queue worker) keeps the
  deployment simple — with a documented path to queues for scale.

---

## 7. Data model (MySQL)

| Table | Purpose |
|---|---|
| `users` | accounts + role, MFA secret/recovery (encrypted/hashed), IP/lockout fields |
| `sites` | named groupings of sources |
| `api_sources` | connector config: vendor, base_url, auth, request/mappings, interval, secret mode + ciphertext |
| `source_runs` | each refresh attempt (status, duration, records, error) |
| `snapshots` | point-in-time pull with pre-aggregated `summary` JSON |
| `endpoints` | one normalized device row per snapshot (+ `raw` payload) |
| `dashboards` | per-user saved widget layouts |
| `login_events` | every login attempt (success/fail, new-IP, reason) |
| `known_ips` | per-user trusted IP baseline |
| `audit_logs` | who did what, when, from where |

---

## 8. API surface (grouped)

- **Auth:** `POST /login`, `POST /login/mfa`, `POST /logout`, `GET /me`, `PUT /password`
- **MFA:** `POST /mfa/setup|confirm|disable|recovery-codes`
- **Sources:** `GET /sources/presets`, `POST /sources/test`, `GET/POST/PUT/DELETE /sources[/{id}]`, `POST /sources/{id}/refresh|unlock`, `GET /sources/{id}/runs`
- **Sites:** `GET/POST/PUT/DELETE /sites[/{id}]`, `POST /sites/{id}/assign`
- **Insights (scope-aware):** `GET /insights/summary|aggregate|trends|data`, `POST /insights/evaluate`
- **Dashboards:** `GET /dashboards`, `GET /dashboards/default`, `POST/PUT/DELETE /dashboards[/{id}]`
- **Admin (`role:admin`):** `/admin/users…`, `/admin/login-events`, `/admin/audit-logs`
- **Health:** `GET /health`

All non-auth routes sit behind `auth:sanctum` + an activity-tracking middleware.

---

## 9. Security model (technical + logical)

| Control | Implementation | Logic |
|---|---|---|
| Password storage | Argon2id, tunable cost, auto-rehash | Memory-hard hashing resists cracking |
| MFA | TOTP + hashed recovery codes | Stolen password ≠ access |
| Session | Sanctum SPA cookies, `SESSION_ENCRYPT`, CSRF | No JS-readable tokens; cross-site forgery blocked |
| API-key secrecy | AES-256-GCM, dedicated key, never-stored option | Protect the only real secret; honest trade-offs |
| RBAC | role middleware + helpers | Least privilege; monitoring ≠ action |
| IP anomaly | known-IP baseline + red flag | Surface takeover/insider risk |
| Brute force | attempt counter + lockout | Slow credential stuffing |
| Audit | append-only `audit_logs` | Accountability & compliance evidence |
| Transport | HTTPS + secure cookies | Confidentiality/integrity in transit |

This is the "trust case": *a monitoring tool built to the standards it monitors.*

---

## 10. Deployment topology

```
  dashboard.<domain>  (CloudPanel site)        api.<domain>  (CloudPanel site)
  nginx 443 ──reverse proxy──▶ Node :PORT      nginx 443 ──▶ nginx :8080 ──▶ PHP-FPM
       (PM2-managed `next start`)                    root = Laravel /public
                                                cron: * * * * * artisan schedule:run
```

- **Frontend:** built (`npm run build`), run as a Node process via **PM2** on a unique
  port, nginx reverse-proxies `/` to it. `NEXT_PUBLIC_API_URL` baked at build time.
- **Backend:** nginx doc-root = Laravel `public/`; PHP-FPM; scheduler cron every minute.
- **Cross-subdomain auth:** `SESSION_DOMAIN=.<root-domain>`, `SameSite=Lax`, secure
  cookies, CORS allows the frontend origin with credentials.
- Full steps: `docs/DEPLOYMENT-BACKEND.md` and `docs/DEPLOYMENT-FRONTEND.md`.

---

## 11. Project structure

```
backend/   app/Models · app/Services/{Crypto,Connectors,Ingest,Security} ·
           app/Http/Controllers/Api · app/Console/Commands · routes/api.php · database/
frontend/  src/app/(auth + (app) pages) · src/components/{ui, widgets, shell, wizard} ·
           src/lib/{api, auth, queries, use-scope, types, format}
docs/      DEPLOYMENT-* · CONNECTING-SOURCES · VALUE-JUSTIFICATION · OBJECTIONS-AND-SCENARIOS ·
           business-case.pdf · this file
```

---

## 12. Key design decisions & trade-offs

1. **Cookie SPA over bearer tokens** — safer against XSS; cost is cross-subdomain cookie
   config (`SESSION_DOMAIN`).
2. **Pre-aggregated snapshots** — fast trends/dashboards; cost is storage + a retention
   policy for endpoint detail.
3. **Generic connector + presets** over per-vendor SDKs — broad coverage, less code;
   cost is occasional per-tenant field-mapping tweaks.
4. **Inline scheduled ingestion** (no queue) — simplest reliable deploy; documented path
   to queued workers for very large fleets.
5. **Report/visualize only, not respond** — deliberately complements vendor consoles
   rather than duplicating detection/response; keeps scope and risk contained.

---

## 13. Limitations & future work

- Reports & visualizes coverage/compliance — does **not** perform detection or response
  (that stays in the vendor consoles).
- Data freshness = the refresh interval; accuracy = each vendor API's accuracy.
- Per-login secret mode can't auto-refresh in the background (by design).
- Natural next steps: queued ingestion for huge fleets, per-site stat cards on the
  All-sites view, alerting/notifications on rule breaches, SSO/SAML, and saved reusable
  rule library.
```
