# Monitoring API for UI developers

This is the contract for building the customer-facing monitoring UI. It covers every endpoint the UI may call, the exact response shapes, the rules the UI must follow, and a suggested screen layout. Everything here was verified against the live service.

## 1. What the product is

Customers add **hosts** (anything with an address: a server, a website, a router, a VM) to monitoring, attach **checks** to them (a ping, an HTTP request), and the service runs those checks continuously. A host is **up** or **down** depending on its *host check*. When a check fails, an **alert** (incident) opens. Customers can be notified through **channels** (webhooks). Check results are stored as **metrics** and can be drawn as graphs.

Each customer account is one isolated tenant. The UI never sees or names a tenant; it always acts for the logged-in user's current account.

Vocabulary used by the API (use these words in the UI too):

| Word | Meaning |
| --- | --- |
| host | a monitored thing (server-side name: device) |
| check | one probe run on a host at an interval (ping, http) |
| host check | the one check that decides whether the host is up or down |
| alert | an incident opened when a check turns bad |
| channel | a webhook that receives alerts |
| site | an optional location (datacenter, office) to group hosts |

## 2. Base URL, auth, conventions

- Base path: `/monitoring` on the same API host and with the same login as the other API endpoints (for example `/iam`). Send the usual `Authorization: Bearer <token>` header. Send and accept JSON.
- The tenant is the user's **current account**. If the user switches account, call again; the data changes.
- Success bodies are wrapped: `{"data": ...}`. Deletes return `204` with no body. `POST .../run` and `.../replay` return `202`.
- **Lists are not paginated.** The whole list comes back. Use the filters below on `alerts`, and expect hosts and checks to be small (hundreds at most).
- All times are ISO 8601. Times from the monitoring service are UTC (`...Z`); times in metrics responses carry the user's server offset. Parse them, do not string-compare.
- All ids are UUID strings and are named `*_id` in requests.

### Errors

Monitoring errors have one shape:

```json
{"error": {"type": "limit-reached", "message": "human readable text"}}
```

`message` is safe to show to the user. Branch on `type` and the HTTP status:

| Status | `type` (examples) | What to do |
| --- | --- | --- |
| 403 | `forbidden` | the user is read-only: disable write actions (see roles) |
| 403 | `tenant-suspended` | the account is suspended: show a banner, reads still work |
| 409 | `tenant-deleted` | monitoring was removed for this account on the monitoring service: show "Monitoring is unavailable for this account. Contact support." and disable every action; an administrator can restore it |
| 404 | `not-found` | object gone or not in this account |
| 409 | `limit-reached`, `already-exists`, `in-use`, `has-children`, `plugin-change` | show `message`; the action conflicts with current state |
| 422 | `invalid-value` | field problem; `message` names the field (for example `plugin: unknown plugin "x"`) |
| 422 | (Laravel format) | request validation failed, see below |
| 501 | `not-supported` | feature not available on this server |
| 502 / 503 | `monitoring-unavailable` | monitoring service unreachable; show a retry state |

Request validation (missing or malformed fields) uses the standard Laravel body instead: `{"message": "...", "errors": {"name": ["The name field is required."]}}` with status 422. Show each message next to its field.

### Roles

Each user is either **operator** or **read-only** in monitoring. This is decided by the backend (a user with the `cloud-resource-owner` role is operator; everyone else is read-only). The API does not tell the UI the role, so:

- Always render read views for everyone.
- Show write actions (create, edit, delete, acknowledge, run) by default, and when a call returns `403 forbidden`, show "You have read-only access" and hide the write actions for the session.
- Nobody gets admin.

## 3. Screens and the calls behind them

Suggested navigation: **Overview**, **Hosts** (list and detail), **Alerts**, **Notification channels**, **Sites** (secondary).

### 3.1 Overview

Build from two calls, both polled every 30 seconds:

- `GET /hosts`: count hosts by `status.availability` (`up`, `down`, `unknown`, `unmonitored`, `disabled`).
- `GET /alerts?status=active`: the open and acknowledged alerts, newest first.

Show: hosts up/down tiles, open alert count by severity, list of the latest alerts, and hosts that are down.

An account that has never used monitoring has no hosts: show an empty state with a "Add your first host" action.

### 3.2 Hosts list

`GET /hosts` with optional filters `type`, `site_id`, `parent_id`, `availability` (`up|down|unknown|unmonitored|disabled`).

Host object:

```json
{
  "id": "01a10762-2099-715c-91d9-52e7e72ac570",
  "name": "Google DNS",
  "address": "8.8.8.8",
  "type": "network",
  "tags": {"purpose": "usage-sample"},
  "notes": null,
  "site_id": null,
  "parent_id": null,
  "external_id": null,
  "status": {
    "availability": "up",
    "since": "2026-10-04T14:47:22Z",
    "health": "ok",
    "open_incidents": 0
  }
}
```

- `status.availability`: `up`, `down`, `unknown` (no result yet), `unmonitored` (no host check), `disabled` (host check switched off). Map to colours: up green, down red, unknown grey, unmonitored grey with a hint "add a check", disabled grey.
- `status.health`: `ok`, `warning`, `critical`: the worst open alert on the host. Use it for a secondary badge.
- `status.since` is when availability last changed (null if unmonitored). Show "down for 12 min".
- `status.open_incidents`: number of open alerts on the host.
- `type` is one of: `network`, `server`, `bmc`, `camera`, `web`, `hypervisor_pool`, `hypervisor_host`, `vm`, `iot`, `ups`, `pdu`, `sensor`, `llm_endpoint`, `llm_application`, `other`. Offer a friendly label and icon for each; `web`, `server`, `network`, `vm` and `other` are the common ones.
- `tags` is a free map of string to string.

### 3.3 Add and edit a host

`POST /hosts`

```json
{
  "name": "Web server",
  "type": "web",
  "address": "example.com",
  "tags": {"env": "prod"},
  "notes": "optional text",
  "site_id": null,
  "parent_id": null
}
```

Required: `name` (max 200), `type`. Optional: `address` (max 2000: an IP, a host name or a URL), `tags` (max 50), `notes`, `site_id`, `parent_id`. Returns `201` with the host object.

Do not send `external_id` or `external_type`: they are for system integrations.

`PATCH /hosts/{host_id}` is a partial update: send only the fields that changed. A field you omit stays; `null` clears an optional field; `tags` merges key by key, so `{"tags": {"env": null, "team": "ops"}}` removes `env` and sets `team`. Returns `200` with the host.

`DELETE /hosts/{host_id}` returns `204`. **It also deletes all the host's checks, its stored history and any hosts inside it.** Show a confirmation that says so.

After creating a host, take the user straight to "add a check", because a host with no check shows as `unmonitored` and is not watched.

### 3.4 Host detail

Use these calls, in this order, on open:

1. `GET /hosts/{host_id}` for the header (name, address, availability).
2. `GET /hosts/{host_id}/checks` for the checks.
3. `GET /alerts?host_id={host_id}&status=active` for open alerts.
4. `GET /hosts/{host_id}/metrics?name[]=...` for graphs (see 3.7).

Poll 1 to 3 every 30 seconds while the page is visible.

Tabs: Overview (status, open alerts, key graph), Checks, Metrics, Settings (edit and delete).

Action **Test now**: `POST /hosts/{host_id}/test` runs every enabled check once, immediately, and returns results without storing them. It can take up to 60 seconds, so show a spinner and use a long timeout:

```json
{"data": [
  {"check_id": "…", "name": "ping 8.8.8.8", "plugin": "icmp", "status": "OK",
   "output": "8.8.8.8 (8.8.8.8): 3/3 replies, avg 18.91 ms, max 18.97 ms, loss 0 %",
   "duration_ms": 3011, "metrics": {"rtt_avg_ms": 18.9, "packet_loss_percent": 0}}
]}
```

Result `status` is `OK`, `WARNING`, `CRITICAL` or `UNKNOWN`. Show `output` as plain text. This is the best way to validate a new host or check before saving.

### 3.5 Checks

Check object (from `GET /hosts/{host_id}/checks`, `GET /checks`, `GET /checks/{check_id}`):

```json
{
  "id": "01a10762-2291-7be0-a418-8dd4592f212b",
  "host_id": "01a10762-2099-715c-91d9-52e7e72ac570",
  "name": "ping 8.8.8.8",
  "plugin": "icmp",
  "config": {"count": 3},
  "interval_seconds": 60,
  "enabled": true,
  "thresholds": [],
  "is_host_check": true,
  "timeout_seconds": null,
  "failure_count": 3,
  "recovery_count": 1,
  "runbook_url": null
}
```

Filters on `GET /checks`: `host_id`, `plugin`, `enabled` (`true|false`). On `GET /hosts/{host_id}/checks`: `plugin`, `enabled`.

`POST /hosts/{host_id}/checks` creates one. Returns `201`.

| Field | Rules |
| --- | --- |
| `name` | required, max 200 |
| `plugin` | required: one of the `type` values from `GET /plugins` (see 4) |
| `config` | object; validated against the plugin's config (see 4); a bad config returns `422 invalid-value` |
| `interval_seconds` | 1 to 86400; default is the plugin's (60); the server enforces a minimum of 30 for customers |
| `timeout_seconds` | 1 to 300 |
| `enabled` | boolean, default true |
| `thresholds` | up to 100 rules (see below) |
| `failure_count` | 1 to 100, default 3: consecutive bad results before an alert opens |
| `recovery_count` | 1 to 100, default 1: consecutive good results before it resolves |
| `is_host_check` | boolean: this check decides up or down. Offer it as "Use this check to decide if the host is up". Use it on exactly one check per host |
| `unknown_is_critical` | boolean, treat an inconclusive result as critical |
| `runbook_url` | URL shown to whoever handles the alert |

`PATCH /checks/{check_id}`: partial update with the same fields except `plugin`, which cannot change (`409 plugin-change`). `enabled: false` pauses a check: it stops within seconds, its open alert resolves, and its history stays. Re-enable with `enabled: true`.

`DELETE /checks/{check_id}` returns `204`.

`POST /checks/{check_id}/run` queues one run now. Returns `202 {"data": {"queued": true}}`. Fetch the state a few seconds later.

`GET /checks/{check_id}/state` returns the current state, or `{"data": null}` before the first result:

```json
{"data": {
  "check_id": "…", "phase": "OK", "status": "OK",
  "output": "GET https://dns.google/: 200 OK in 74 ms",
  "metrics": {"total_ms": 73.9, "status_code": 200},
  "since": "2026-10-04T14:47:22+00:00",
  "last_result_at": "2026-10-04T19:02:22+00:00",
  "incident_id": null
}}
```

- `phase`: `OK`, `PENDING` (failing but not yet confirmed: fewer than `failure_count` bad results), `PROBLEM` (an alert is open).
- `status`: `OK`, `WARNING`, `CRITICAL`, `UNKNOWN`. Colour them green, amber, red, grey.
- `last_result_at` older than about three intervals means the check is not running (for example the account is suspended): show "no recent result".

**Threshold rules** (optional, per check) turn a metric into WARNING or CRITICAL:

```json
{"metric": "total_ms",
 "warning":  {"op": ">", "value": 2000},
 "critical": {"op": ">", "value": 8000},
 "for": "5m"}
```

`metric` must be one of the plugin's metrics (`metrics[].name` from `GET /plugins`). `op` is one of `>`, `>=`, `<`, `<=`, `==`, `!=`, `between`, `outside` (the last two also need `value_max`). `for` is how long the condition must hold (for example `5m`). A rule may have `warning`, `critical` or both. Arrays are replaced whole on PATCH, so always send the full list.

### 3.6 Alerts

`GET /alerts` with filters `status` (`open|acknowledged|resolved|active`; `active` means open plus acknowledged), `severity` (`warning|critical`), `host_id`, `check_id`. Newest first. The result can be long, so always pass a filter, and use `status=active` for dashboards.

```json
{
  "id": "01a106f1-da0f-704c-b589-90034ad8721b",
  "summary": "GET https://example.com: unexpected status 200 OK",
  "severity": "critical",
  "status": "open",
  "host_id": "…",
  "check_id": "…",
  "last_output": "…",
  "opened_at": "2026-10-04T12:44:40+00:00",
  "acknowledged_at": null,
  "resolved_at": null,
  "resolved_by": null
}
```

- `severity`: `warning` amber, `critical` red.
- `status`: `open` needs attention, `acknowledged` someone is on it, `resolved` is closed.
- `resolved_by`: `recovery` (fixed itself), `manual`, `check-deleted`, `check-disabled`.

Actions (operators only):

- `POST /alerts/{alert_id}/acknowledge` with optional body `{"note": "looking into it"}`. The note is stored as a comment. Acknowledging twice is harmless. Returns the alert.
- `POST /alerts/{alert_id}/resolve` closes it. If the problem persists, a new alert opens, so warn the user.

There is no endpoint to list an alert's comments from this API.

### 3.7 Metrics and graphs

Two calls. Both are on a host.

`GET /hosts/{host_id}/metrics/series` lists what can be graphed (use it to build the metric picker). Optional: `check_id`, `name[]`.

```json
{"data": [{"name": "total_ms", "unit": "ms", "kind": "gauge", "plugin": "http", "object": "", "check_id": "…"}]}
```

`GET /hosts/{host_id}/metrics` returns points. Query parameters (all optional):

| Param | Meaning |
| --- | --- |
| `name[]` | metric names; repeat the parameter (`name[]=total_ms&name[]=ttfb_ms`) |
| `from`, `to` | ISO 8601; default is the last hour; `to` must be after `from` |
| `step` | bucket size in seconds, 10 to 2592000; chosen automatically when omitted |
| `agg` | `avg` (default), `min`, `max`, `sum` |
| `check_id` | limit to one check |
| `object` | sub-object key, rarely needed |

```json
{"data": {
  "from": "2026-10-04T15:14:12+03:00", "to": "2026-10-04T16:14:12+03:00",
  "step": 30, "resolution": "raw",
  "series": [{
    "name": "total_ms", "unit": "ms", "object": "", "check_id": "…",
    "points": [{"t": "2026-10-04T15:14:00+00:00", "v": 370.9}, {"t": "2026-10-04T15:14:30+00:00", "v": 72.1}]
  }]
}}
```

Rules for the chart:

- **A missing bucket means no data.** Do not interpolate and do not draw zero. Break the line at gaps.
- Use `unit` for the axis label: `ms`, `bytes`, `percent`, `1` (a plain number such as a status code).
- Suggested range presets and steps (the service caps a series at 10,000 points):

| Range | Step |
| --- | --- |
| 1 hour | 30 |
| 24 hours | 300 |
| 7 days | 3600 |
| 30 days | 3600 |
| 1 year | 86400 |

  When the user does not choose a step, omit `step`; the backend picks the same values.
- A series for a host with no data, or a host that is not in this account, comes back as `"series": []`, not as an error.
- Data exists only from the moment a check starts. Retention is set by the platform operator and can change, so do not promise a history length in the UI. Long ranges are served from coarser rollups (the `resolution` field says `raw`, `5m` or `1h`), so they look smoother than short ranges.
- Good default graphs: response time for HTTP (`total_ms`), round-trip time and `packet_loss_percent` for ping.

### 3.8 Notification channels

A channel is a webhook that receives alerts. The customer's own system gets an HTTPS POST for each alert event. This is the only way customers get notified today (there is no built-in email).

`GET /channels`:

```json
{"data": [{
  "id": "01a10815-08bf-753f-ab8a-a2a2cfe86b2f",
  "name": "Ops webhook",
  "url": "https://example.com/hook",
  "enabled": true,
  "disabled_reason": null,
  "timeout_seconds": 10,
  "header_names": ["X-Env"],
  "severity": ["critical"],
  "device_types": ["network"],
  "tags": {},
  "previous_secret_valid_until": null
}]}
```

`POST /channels` creates one. Returns `201` with the channel plus a `secret` field.

| Field | Rules |
| --- | --- |
| `name` | required, max 200 |
| `url` | required, a public `http` or `https` URL (max 2000). Private, local and internal addresses are refused with `422` |
| `enabled` | default true |
| `timeout_seconds` | 1 to 30, default 10 |
| `headers` | map of header name to value, up to 20, added to every delivery. Values are write-only |
| `severity` | `["warning"]`, `["critical"]` or both; omit for all |
| `device_types` | only alerts on these host types; omit for all |
| `tags` | only alerts on hosts with these tags; omit for all |

**The `secret` (starts with `whsec_`) is shown once, on create and on rotate, and never again.** The UI must show it in a dialog with a copy button and a clear warning ("You will not be able to see this again. Store it now."), and must not keep it in any list or log. The customer uses it to verify deliveries; the full guide is `docs/monitoring-webhooks.md`. Link to it from the dialog.

Other calls:

- `PATCH /channels/{channel_id}`: partial update. Same fields as create. Omit `headers` to keep the existing header values; send `{"headers": {"X-Env": null}}` to remove one. Never returns the secret.
- `PATCH` with `{"enabled": true}` re-enables a channel the service disabled (see `disabled_reason`).
- `DELETE /channels/{channel_id}` returns `204`.
- `POST /channels/{channel_id}/test` sends a test event and returns `{"data": {"ok": false, "status_code": 405, "response": "…", "error": null}}`. `ok` is false when the receiver answered an error or was unreachable. A blocked address shows up here in `error`. Show `status_code`, `error` and a short excerpt of `response`.
- `POST /channels/{channel_id}/rotate-secret` returns `{"data": {"secret": "whsec_…", "previous_secret_valid_hours": 24}}`. Show the new secret once. The old one keeps working for 24 hours, so the customer can switch over without downtime.
- `GET /channels/{channel_id}/deliveries?status=` (`pending|delivered|failed|cancelled`) lists delivery attempts, newest first: `id`, `event_id`, `event_type`, `subject`, `status`, `attempts`, `last_status_code`, `last_error`, `delivered_at`, `created_at`. Use it for a "Delivery log" tab with a failed filter.
- `POST /channels/{channel_id}/deliveries/{delivery_id}/replay` re-queues one delivery; returns `202`. It returns `409` while that delivery is still pending.

If `enabled` is false and `disabled_reason` is set, the service turned the channel off (usually because the customer's receiver answered `410 Gone`). Show a prominent warning with the reason and a "Re-enable" button that sends `{"enabled": true}`.

Limits: an account can have at most 20 channels; a create beyond that returns `409 limit-reached`.

### 3.9 Sites

Optional grouping of hosts by location.

- `GET /sites` returns `[{"id", "name", "country", "timezone", "address"}]`.
- `POST /sites` with `name` (required), `country` (two capital letters, for example `TR`), `timezone` (an IANA name such as `Europe/Istanbul`), `address`. Returns `201`.
- `DELETE /sites/{site_id}` returns `204`, or `409` while hosts still use the site (show the message).

Sites cannot be edited yet; to change one, delete and recreate it. Treat sites as an optional "group by" and a host field (`site_id`), not a main screen.

### 3.10 Account status

`GET /tenant` returns `{"data": {"name": "...", "status": "active", "capabilities": ["tenants","hosts","checks","alerts","sites","metrics","notifications","plugins"]}}`. Call it once on entering the monitoring section. It also creates the account's monitoring tenant on first use, so the first call can take a second longer. If `status` is `suspended`, show a banner ("Monitoring is paused for this account") and disable write actions; checks are not running while suspended.

## 4. Plugins (check types)

`GET /plugins` returns the check types the service offers. **Build the check form from this response instead of hard-coding types**, because new types are added over time (SNMP checks are on the way).

```json
{"data": [{
  "type": "http",
  "description": "HTTP(S) request: status code, keyword and timing breakdown.",
  "kind": "check",
  "default_interval_seconds": 60,
  "min_interval_seconds": 5,
  "config_schema": { "...": "JSON Schema of the check's config" },
  "metrics": [{"name": "total_ms", "unit": "ms", "kind": "gauge", "description": "Whole request including the body read"}],
  "credential_types": ["http_basic", "http_bearer"]
}]}
```

- `type` is the value to send as `plugin` when creating a check.
- `config_schema` is a JSON Schema for the check's `config`. Render one field per property (`type`, `default`, `enum` and `description` tell you the input kind, the prefilled value and the help text), and send only the fields the user changed. Required fields are listed in its `required` array when present.
- `metrics` lists what the check measures: use it for the threshold-rule metric picker and for naming graphs. `unit` is for axis labels.
- `min_interval_seconds` is the plugin's own floor. Customers have a higher floor of 30 seconds, so use `max(plugin floor, 30)` as the minimum in the form.
- `credential_types` lists credential kinds a check of this type can use. The API has no credentials endpoints yet, so ignore a plugin that needs a credential (any plugin whose `config_schema` or description requires one), or show it disabled with "not available yet". `http` and `icmp` work without one.
- The list can include plugins that customers cannot use yet, for example SNMP, because private networks are unreachable. If a check of such a type fails, the check output explains why.

The two types that exist today, for reference:

### `icmp`: ping

Checks that the host answers and measures round-trip time and packet loss. CRITICAL at 100 % loss. Default interval 60 s.

- `config`: `count` (integer, default 3: packets per run), `interval_ms` (integer, default 200: pause between packets), `size` (integer, default 56: packet bytes). All optional; an empty config is fine.
- Metrics: `rtt_avg_ms` (ms), `rtt_max_ms` (ms), `packet_loss_percent` (percent).
- The host's `address` is what gets pinged; a ping check has no `url`.

### `http`: web request

Makes an HTTP(S) request and checks the answer. Default interval 60 s.

- `config`:
  - `url` (string): the address to request. Use the host's address if the user does not give one.
  - `method` (string, default `GET`)
  - `expected_status` (**array** of integers, for example `[200]`; a single number is rejected)
  - `keyword` (string: must appear in the body), `keyword_absent` (string: must not appear)
  - `headers` (object of string to string), `body` (string)
  - `follow_redirects` (boolean, default true), `max_redirects` (integer, default 5)
  - `insecure_skip_verify` (boolean: accept invalid TLS certificates)
  - `source_ip` (string)
- Metrics: `dns_ms`, `connect_ms`, `tls_ms`, `ttfb_ms`, `total_ms` (all ms), `status_code` (plain number), `body_bytes` (bytes).
- Private, local and internal addresses are blocked for customers; the check then reports a network-policy error in its output.

Pick a sensible form for each: the UI should show the plugin's own fields, with the defaults above, and send only fields the user changed.

## 5. Behaviours the UI must respect

1. **Polling, not push.** There is no live stream. Poll lists and states every 30 seconds while the page is visible, and pause when the tab is hidden. Metrics graphs can refresh every 30 to 60 seconds.
2. **Interval floor.** Checks cannot run more often than every 30 seconds for customers. Do not offer lower values; the API returns `422` for them.
3. **First result delay.** A new check has no state until it runs the first time (seconds to one interval). Show "waiting for first result" and let the user press "Run now".
4. **Alerts need time.** A failing check shows `PENDING` until `failure_count` bad results in a row; the alert opens after that. With a 60 s interval and the default of 3, that is about 3 minutes. Show `failure_count` next to the interval in the check form, with a short explanation.
5. **Suspended accounts.** Reads work, writes return `403 tenant-suspended`.
6. **Deleting a host deletes its history.** Always confirm.
7. **Never show the signing secret anywhere except the create and rotate dialogs.**
8. **Do not call the monitoring service directly.** Only the `/monitoring` endpoints in this document exist for the UI.
9. **Usage and billing are not part of this API.** Do not show prices or usage numbers from it. Only checks are billed (weighted: a ping counts 1, an HTTP check counts 2); hosts are free. If the product wants to show "your usage", ask the backend for a dedicated endpoint.

## 6. Known gaps (do not build around them; ask the backend)

1. **No credentials endpoints.** Plugins that need a credential (SNMP) cannot be set up through this API yet.
2. **No role endpoint.** The UI discovers read-only users by the first `403 forbidden`.
3. **No alert comments list**, no escalation, no schedules. Alerts can be acknowledged (with a note) and resolved.
4. **No credentials, no dependency map, no SNMP checks.** Not available through this API.
5. **No email or in-app notification.** Channels (webhooks) are the only notification route.
6. **Sites cannot be edited.**
7. **Lists are not paginated.** If an account grows past a few hundred hosts, the host list and alerts need paging; ask the backend.

## 7. Quick reference

| Purpose | Method and path |
| --- | --- |
| account status | `GET /tenant` |
| check types | `GET /plugins` |
| list hosts | `GET /hosts` (filters `type`, `site_id`, `parent_id`, `availability`) |
| create host | `POST /hosts` |
| host detail | `GET /hosts/{host_id}` |
| edit host | `PATCH /hosts/{host_id}` |
| delete host | `DELETE /hosts/{host_id}` |
| test host now | `POST /hosts/{host_id}/test` |
| host's checks | `GET /hosts/{host_id}/checks` |
| add check | `POST /hosts/{host_id}/checks` |
| all checks | `GET /checks` (filters `host_id`, `plugin`, `enabled`) |
| check detail | `GET /checks/{check_id}` |
| edit check | `PATCH /checks/{check_id}` |
| delete check | `DELETE /checks/{check_id}` |
| check state | `GET /checks/{check_id}/state` |
| run check now | `POST /checks/{check_id}/run` |
| graph series list | `GET /hosts/{host_id}/metrics/series` |
| graph points | `GET /hosts/{host_id}/metrics` |
| list alerts | `GET /alerts` (filters `status`, `severity`, `host_id`, `check_id`) |
| acknowledge alert | `POST /alerts/{alert_id}/acknowledge` |
| resolve alert | `POST /alerts/{alert_id}/resolve` |
| list channels | `GET /channels` |
| create channel | `POST /channels` |
| edit channel | `PATCH /channels/{channel_id}` |
| delete channel | `DELETE /channels/{channel_id}` |
| test channel | `POST /channels/{channel_id}/test` |
| rotate secret | `POST /channels/{channel_id}/rotate-secret` |
| delivery log | `GET /channels/{channel_id}/deliveries` |
| replay delivery | `POST /channels/{channel_id}/deliveries/{delivery_id}/replay` |
| list sites | `GET /sites` |
| create site | `POST /sites` |
| delete site | `DELETE /sites/{site_id}` |

## 8. Example flows

**Add a website and watch it**

1. `POST /hosts` `{"name":"Company site","type":"web","address":"example.com"}` → keep `data.id`.
2. `POST /hosts/{id}/checks` `{"name":"Homepage","plugin":"http","config":{"url":"https://example.com","expected_status":[200]},"is_host_check":true,"interval_seconds":60}`.
3. `POST /hosts/{id}/test` to confirm it works before leaving the page.
4. Poll `GET /hosts/{id}`: `status.availability` becomes `up` within about a minute.

**Get told when it breaks**

1. `POST /channels` `{"name":"Ops","url":"https://hooks.example.com/mon","severity":["critical"]}`; show `data.secret` once.
2. `POST /channels/{id}/test` to check the receiver.
3. When a check fails, the customer's URL receives the event; see `docs/monitoring-webhooks.md` for the payload and signature check.

**Investigate an outage**

1. `GET /alerts?status=active` → pick an alert.
2. `GET /hosts/{alert.host_id}/metrics?name[]=total_ms&from=<an hour before opened_at>` to see what happened.
3. `POST /alerts/{id}/acknowledge` with a note; later `POST /alerts/{id}/resolve` once fixed.
