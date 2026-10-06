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
| 409 | `tenant-deleted` | the account's monitoring was removed on the monitoring service and could not be restored automatically (the account is suspended): show "Monitoring is unavailable for this account. Contact support." and disable every action. A deleted tenant of an active account is restored automatically, see below |
| 503 | `monitoring-preparing` | the account's monitoring is being restored by another request right now: show the "preparing" wait screen and retry after a few seconds |
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
- `discovered` is `null` for hosts the customer created. For hosts that a **collector discovered** (an XCP-ng pool check, `xapi.pool`, turns every pool host and VM into a host of its own, types `hypervisor_host` and `vm`) it is `{"check_id": "<the pool check>", "key": "vm:<uuid>", "gone_at": null}`. Treat these differently in the list: show a badge ("discovered from <pool>"), group them under the pool, and:
  - only `tags`, `notes` and `external_id` can be edited; renaming, retyping or moving them is refused (`422`), and deleting one is refused too (delete the pool check to remove them all). Hide those actions;
  - a running VM's `parent_id` is its pool host, a halted VM's is the pool host device; a migration changes `parent_id` but not the host `id`;
  - they do not count toward the account's host limit and are not billed, but checks the customer adds on them are billed normally;
  - when `gone_at` is set the pool stopped reporting the host; it is deleted 7 days later. Show it greyed out as "removed";
  - their alerts and graphs belong to their own host id (`GET /hosts/{host_id}/metrics?...`).
  A pool can add hundreds of hosts at once, so the host list can grow suddenly: use the `type` and `availability` filters, and expect to need paging (see section 6).

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

Filters on `GET /checks`: `host_id`, `plugin`, `enabled` (`true`, `false`, `1` or `0`). On `GET /hosts/{host_id}/checks`: `plugin`, `enabled`.

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
| `credentials` | map of role to credential id, for example `{"auth": "<credential id>"}` (see 3.11). Needed by plugins that list `credential_types` |

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

`metric` must be one of the plugin's metrics (`metrics[].name` from `GET /plugins`). On a collector, add `"object": "Gi1/0/1"` (an object's key or name) to target one object; without it the rule applies to every object. Do not send `object` on a plain check (422). `op` is one of `>`, `>=`, `<`, `<=`, `==`, `!=`, `between`, `outside` (the last two also need `value_max`). `for` is how long the condition must hold (for example `5m`). A rule may have `warning`, `critical` or both. Arrays are replaced whole on PATCH, so always send the full list.

### 3.6 Alerts

`GET /alerts` with filters `object_key` (one collector object), `suppressed` (`true` or `false`) and `status` (`open|acknowledged|resolved|active`; `active` means open plus acknowledged), `severity` (`warning|critical`), `host_id`, `check_id`. Newest first. The result can be long, so always pass a filter, and use `status=active` for dashboards.

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
- `resolved_by`: `recovery` (fixed itself), `manual`, `check-deleted`, `check-disabled`, `object-gone` (the device stopped reporting that object, for example a port that was removed).
- `object_key` and `object_name` are set for alerts of a **collector** check (3.12): the object the alert is about, for example `Gi1/0/2`. They are `null` for a plain check. Show them next to the host name ("sw-core: Gi1/0/2 down").
- `suppressed` is `true` when an upstream host check explains this alert (the switch is down, so the servers behind it are too). Suppressed alerts are not sent to channels while their root alert is open. Show them greyed out, "caused by <root>", using `root_incident_id` and `root_host_id`. Hide them by default with `?suppressed=false`.
- `flapping` is `true` while the check keeps changing state; show a flapping badge.
- `rule_id` and `rule_name` say what raised it: a threshold rule of the check, or `whoopsy` / `Whoopsy!` for the premium alerting (3.13).

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
| `agg` | `avg` (default), `min`, `max`, `sum`; or `stddev` (standard deviation); or a percentile written `p<number>`: `p50`, `p95`, `p99`, `p99.9` (up to 3 decimals, between 0 and 100 exclusive). Percentiles and `stddev` are computed from the raw samples inside each bucket, **never** by averaging bucket values, so a slow outlier is not hidden. They need raw data, which the service keeps for a limited time (about 7 days), and the backend asks for it automatically; a longer window answers `422` |
| `moving_window` | 1 to 1000: replaces every point with the mean of itself and the previous N-1 points (a smoothing line; works with any `agg`) |
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

**Statistics for the whole window.** `GET /hosts/{host_id}/metrics/summary` returns one set of numbers per series for a range, for the "Min, Avg, P50, P95, P99, Max" row under a graph. Parameters: `name[]` (repeat it: one request per check carries all its metrics), `from`, `to` (default: the last hour), `check_id`, `object`, `percentile[]` (extra percentiles such as `p90` and `p99.9`, up to 10) and `window` (1 to 10000: also give the statistics of the last N samples).

```json
{"data": {
  "from": "2026-10-07T10:00:00Z", "to": "2026-10-07T11:00:00Z",
  "resolution": "raw", "exact": true,
  "series": [{
    "name": "total_ms", "unit": "ms", "object": "", "check_id": "…",
    "count": 60, "min": 66.8, "max": 85.6, "avg": 72.5, "stddev": 4.9,
    "p50": 70.2, "p95": 81.4, "p99": 85.2,
    "percentiles": {"p90": 79.1},
    "last": 70.9, "last_at": "2026-10-07T10:59:12Z",
    "moving": null
  }]
}}
```

- The values come from raw samples, so they are **exact** (`exact` is always `true`; the service never approximates). A value is `null` when the window has no samples (`count` 0), and `stddev` is `null` with a single sample.
- `moving` is only present with `window=N`: `{"window", "count", "avg", "stddev"}` over the last N samples.
- `422 invalid-value` for a percentile outside 0 to 100 or a window longer than the raw retention, with the limit in the message. More than two million raw samples in one request is also refused: ask for a shorter range.
- It is cheap enough for the last hour: poll it every 30 seconds with all of a check's metric names in one request. For ranges longer than the raw retention use the graph (`avg`, `min`, `max`) only and hide the percentile row, or label it unavailable.
- Percentiles tell more than averages for response times: P95 and P99 are what the slowest users experience; the average hides the slow tail.


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
| `site_ids` | only alerts on hosts in these sites; omit for all |
| `check_ids` | only alerts of these checks (one alarm to its own channel); omit for all |
| `event_types` | which events to send: `monitoring.incident.opened`, `.updated`, `.acknowledged`, `.resolved`, `.commented`, and the account limit events `monitoring.tenant.device_limit_reached` and `monitoring.tenant.check_limit_reached`. Default: opened, updated, acknowledged and resolved incidents, and the limit events. A limit event has no host, check or severity, so a channel that filters on `severity`, `device_types`, `tags`, `site_ids` or `check_ids` never receives it: to be told about limits, create a channel with no such filters (or one that names the limit events and nothing else) |
| `group_by` | send one event for several incidents that share these fields, after `group_wait_seconds`. Allowed: `root_device_id`, `device_id`, `site_id`, `severity`, `check_id`, `plugin`, `device_type`. `["root_device_id"]` turns a switch outage into one message. Empty: every incident on its own |
| `group_wait_seconds` | 0 to 600; how long to collect a group before sending it (default 30; only used with `group_by`) |
| `repeat_interval_seconds` | 300 to 604800: resend open, unacknowledged incidents this often (event `monitoring.incident.renotify`); `null` or omitted: never |
| `steps` | escalation, a list of `{"after_seconds": 0, "labels": {...}, "only_if_unacknowledged": false, "schedule": {...}}`. The first step is the notification itself (`after_seconds` 0); later steps resend after that many seconds, optionally only while nobody acknowledged the incident, with `labels` merged over the channel's, and optionally only within a time window (`schedule`: `from`, `to`, `timezone`, `days`). Escalations arrive as `monitoring.incident.escalated` |

**The `secret` (starts with `whsec_`) is shown once, on create and on rotate, and never again.** The UI must show it in a dialog with a copy button and a clear warning ("You will not be able to see this again. Store it now."), and must not keep it in any list or log. The customer uses it to verify deliveries; the full guide is `docs/monitoring-webhooks.md`. Link to it from the dialog.

Other calls:

- `PATCH /channels/{channel_id}`: partial update. Same fields as create. Omit `headers` to keep the existing header values; send `{"headers": {"X-Env": null}}` to remove one. Never returns the secret.
- `PATCH` with `{"enabled": true}` re-enables a channel the service disabled (see `disabled_reason`).
- `DELETE /channels/{channel_id}` returns `204`.
- `POST /channels/{channel_id}/test` sends a test event and returns `{"data": {"ok": false, "status_code": 405, "response": "…", "error": null}}`. `ok` is false when the receiver answered an error or was unreachable. A blocked address shows up here in `error`. Show `status_code`, `error` and a short excerpt of `response`.
- `POST /channels/preview` with `{"severity": "critical", "host_id": "...", "event_type": "monitoring.incident.opened", "check_id": "..."}` (all optional) says which channels such an incident would notify, in evaluation order: `{"data": [{"channel_id": "...", "name": "...", "matched": true, "notifies": true}]}`. `matched` means the filters fit, `notifies` means it would really send. Sends nothing. Use it for a "Which channels would be notified?" helper.
- `POST /channels/{channel_id}/deliveries/replay` with `from` and `to` (and optional `status` `failed` or `cancelled`) sends all failed deliveries of the channel in that period again, in one call. Returns `202`. The delivery list reaches back 30 days.
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

### 3.11 Credentials

What checks log in with: an SNMP community or SNMPv3 user, an HTTP bearer token or basic login, and so on. Needed by plugins whose `credential_types` is not empty. **Secrets are write-only: no endpoint ever returns one**; a credential only shows which secret fields are set.

- `GET /credential-types` returns the kinds and their fields: `{"data": [{"name": "snmp_v2c", "description": "...", "fields_schema": {...}}]}`. In `fields_schema.properties`, a property with `writeOnly: true` is a secret: render it as a password input. Kinds today: `snmp_v2c` (`community`), `snmp_v3` (`username`, `security_level`, `auth_protocol`, `auth_password`, `priv_protocol`, `priv_password`, `context_name`), `http_basic` (`username`, `password`), `http_bearer` (`token`), plus `redfish`, `ipmi`, `xapi`, `rtsp` and `mqtt`.
- `GET /credentials` lists them:

```json
{"data": [{"id": "…", "name": "Core switch", "type": "snmp_v2c", "fields": {}, "secrets_set": ["community"]}]}
```

  `fields` holds the non-secret fields; `secrets_set` names the secret fields that have a value. Show "set" for those, never the value, and never prefill a secret field.
- `POST /credentials` with `{"name": "...", "type": "snmp_v2c", "fields": {"community": "public"}}` creates one (`201`). The `fields` required by the type must all be present (`422` otherwise).
- `PATCH /credentials/{credential_id}`: send only what changes. A secret you do not send **keeps its stored value**, so an edit form leaves secret inputs empty and sends one only if the user typed a new one.
- `DELETE /credentials/{credential_id}` returns `204`, or `409 in-use` while a check still uses it (show the message; the user must change or delete those checks first).

To create an SNMP check: create the credential, create the check with `"credentials": {"auth": "<credential id>"}`, then press "Test now".

Only users with the manager role can see or change credentials; read-only users get `403` on these endpoints, so hide the screen for them.

### 3.12 Collectors (many objects per check)

A plugin with `kind: "collector"` (for example `snmp.interfaces`) reports **many objects in one check**: every port of a switch, every outlet of a PDU. It is created like any check (3.5), needs an SNMP credential, and cannot be the host check. For billing, one collector is one check, however many objects it has.

`GET /checks/{check_id}/objects` lists the objects and the state of each (`[]` for a plain check, or before the first run). Optional filters: `status` (`OK`, `WARNING`, `CRITICAL`, `UNKNOWN`), `include_gone` (`true` or `false`, default true).

```json
{"data": [{
  "key": "Gi1/0/2", "name": "Gi1/0/2",
  "labels": {"alias": "uplink to core", "speed": "1000000000", "if_index": "10102"},
  "phase": "PROBLEM", "status": "CRITICAL", "since": "2026-10-06T08:00:00Z",
  "last_output": "Gi1/0/2: down", "last_metrics": {"in_bps": 0, "out_bps": 0},
  "incident_id": "…", "first_seen_at": "…", "last_seen_at": "…", "gone_at": null
}]}
```

- `phase`, `status` and `since` mean what they do on a check (3.5), but per object. `labels` are free text from the device; show `alias` as a subtitle.
- `host_id` is set for collectors that create hosts of their own (an `xapi.pool` check: a VM object has the VM's host id), `null` otherwise; open that host for its own alerts and graphs.
- `gone_at` is set when the device stopped reporting the object (a port filtered out, a card removed). Gone objects are kept for 30 days; show them greyed out, and offer a "Hide removed" toggle that sends `include_gone=false`.
- Each object has its own alert: a 48-port switch with two ports down has two alerts, each with `object_key` and `object_name`. When the device itself does not answer there is one alert for the check (`object_key` is `null`), not one per port.
- Graph per object: `GET /hosts/{host_id}/metrics` and `.../metrics/series` accept `object=Gi1/0/2` and every series carries `object`. Interface metrics: `in_bps`, `out_bps`, `in_errors_rate`, `out_errors_rate`, `in_discards_rate`, `out_discards_rate`, `oper_status`, `speed_bps`.
- `POST /hosts/{host_id}/test` results gain an `objects` list for collectors: `[{"key", "name", "labels", "status", "output", "metrics"}]`; their `metrics` map is empty because collector metrics are per object.
- The `snmp.interfaces` config (from `GET /plugins`): `include` / `exclude` (regular expressions on name or description), `types` (interface type numbers), `admin_up_only` (default true), `down_status` (`critical`, `warning` or `ok`, for interfaces that are admin up but oper down), `max_interfaces` (default 1000), and the SNMP `port`, `timeout_ms`, `retries`. Minimum interval 60 seconds.

### 3.13 Whoopsy! (premium alerting)

Whoopsy! alerts when a check's value leaves its own normal range, without the customer having to guess a threshold: the band is the moving average of the last N results plus or minus some standard deviations. With an average response time of 500 ms and a deviation of 100 ms, a result above 600 ms for three results in a row raises an alert. It suits response times that are not constant (a website that is normally 80 ms).

**It is a paid extra: while it is on, the check is billed at a multiple of its normal price (today 5 times its weight, so an `http` check goes from weight 2 to 10).** The UI must show that before it can be turned on.

Available on checks whose plugin has a `whoopsy_metric` in `GET /plugins` (`http`: `total_ms`, `icmp`: `rtt_avg_ms`, `snmp.get`: `value`). Collectors and plugins without one cannot use it (`422`). Only users with the manager role can change it; read-only users get `403` and the screen should be read-only for them.

- `GET /checks/{check_id}/whoopsy` returns the status:

```json
{"data": {
  "check_id": "…", "enabled": true,
  "settings": {"metric": "total_ms", "window": 7, "deviations": 1, "consecutive": 3, "direction": "above", "min_delta": 0, "severity": "warning"},
  "band": {"points": 7, "mean": 500, "stddev": 81.65, "lower": 418.35, "upper": 581.65, "last_value": 520, "consecutive_hits": 0, "alerting": false},
  "billing": {"multiplier": 5, "plugin_weight": 2, "billed_weight": 10, "applies": true}
}}
```

  `settings` and `band` are `null` while it is off, and `band` is also `null` until the check has run with it on. `band.points` counts the results learned so far: the band only applies once it reaches `settings.window`. `billing.applies` says whether the higher price is in effect now; show `plugin_weight` and `billed_weight` as "normal price x multiplier" and never invent a currency amount here.
- `PUT /checks/{check_id}/whoopsy` turns it on or changes its settings. Every setting is optional:

| Setting | Rules |
| --- | --- |
| `metric` | default: the plugin's `whoopsy_metric`; other plugins must name one |
| `window` | 3 to 1000, default 7: how many recent results make the average |
| `deviations` | above 0 up to 10, default 1: how wide the band is, in standard deviations |
| `consecutive` | 1 to 100, default 3: results outside the band in a row before the alert |
| `direction` | `above` (default), `below` or `both` |
| `min_delta` | smallest half-width of the band, in the metric's unit (default 0); raise it to ignore tiny changes on very steady checks |
| `severity` | `warning` (default) or `critical` |
| `confirm_price` | **must be `true` when Whoopsy! is currently off**, otherwise `422 invalid-value` with a message stating the price. It is not needed to change the settings of a check that already has it on |

  Flow: show the price (`billing`) and a clear confirmation ("Turn on Whoopsy! for this check? It is billed at 5 times the normal price while on."), and only then send `confirm_price: true`. The refused call changes nothing.
- `DELETE /checks/{check_id}/whoopsy` turns it off (`204`). The normal price applies again from that moment.
- `POST /checks/{check_id}/whoopsy/reset` accepts a new normal: the band is learned again from the next results and an open Whoopsy! alert resolves with the next result. `409 whoopsy-off` if it is off. Offer it as "This is the new normal" on a Whoopsy! alert: **the band stays at the last normal while results break it**, so a lasting slowdown keeps alerting until results return to the band or someone presses reset.
- Alerts it raises have `rule_id` `"whoopsy"` and `rule_name` `"Whoopsy!"`; the summary says what happened, for example `Whoopsy!: total_ms = 1000, above 581.65 (moving average 500 ± 1 standard deviations of 81.65 over the last 7 results, 3 in a row)`. Show a Whoopsy! badge on them. Webhooks carry the same `data.check.rule_id`.
- Changing a check (`PATCH /checks/{id}`) never changes Whoopsy!.

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
- `kind` is `check` (one result per run) or `collector` (one result per object, for example every port of a switch; see 3.12).
- `credential_types` lists the credential kinds a check of this type can use (empty: none needed). A plugin with credential types needs a credential in the `auth` role: create it first (3.11), then pass its id when you create the check. `http` and `icmp` work without one.
- Collectors available now: `xapi.pool` (an XCP-ng pool: its hosts, VMs and storage repositories; needs an `xapi` credential, a read-only XCP-ng account; add it on the pool master's host), `snmp.interfaces` (ports of a switch), `snmp.pdu` and `snmp.sensor` (APC power and environment), `redfish.health` (server hardware through its BMC: CPU, memory, drives, fans, power supplies, temperatures; needs a `redfish` credential, a read-only BMC account). Each is one check for billing, however many objects it has.
- `camera.snapshot` (a plain check) fetches a picture from an IP camera and judges it: covered lens or black picture, frozen picture, too dark, overexposed, blurred, or the camera moved. It needs an `rtsp` or `http_basic` credential; its config is in `config_schema` (vendor `auto` by default, which tries Hikvision, Dahua, Axis and ONVIF).
- The list includes plugins that customers cannot always use yet. SNMP plugins (`snmp.system`, `snmp.get`, `snmp.ups`, and the `snmp.interfaces` collector) talk to devices that are usually on private networks, which customers cannot reach until remote probes exist; such a check fails with an explanation in its output. Offer them, but explain that the device must be reachable from the internet.

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

1. **No role endpoint.** The UI discovers read-only users by the first `403 forbidden`.
2. **No alert comments list.** Alerts can be acknowledged (with a note) and resolved.
3. **No dependency map** (which host depends on which); suppression of dependent alerts happens on the server and is visible through `suppressed` on an alert.
4. **No email or in-app notification.** Channels (webhooks) are the only notification route.
5. **Sites cannot be edited.**
6. **Lists are not paginated.** If an account grows past a few hundred hosts, the host list and alerts need paging; ask the backend.
7. **Private networks.** Checks to private addresses (most switches, UPSs and PDUs) cannot run for customers yet.

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
| statistics for a range | `GET /hosts/{host_id}/metrics/summary` |
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
| credential kinds | `GET /credential-types` |
| list credentials | `GET /credentials` |
| create credential | `POST /credentials` |
| edit credential | `PATCH /credentials/{credential_id}` |
| delete credential | `DELETE /credentials/{credential_id}` |
| objects of a collector | `GET /checks/{check_id}/objects` |
| Whoopsy! status | `GET /checks/{check_id}/whoopsy` |
| Whoopsy! on or settings | `PUT /checks/{check_id}/whoopsy` |
| Whoopsy! off | `DELETE /checks/{check_id}/whoopsy` |
| Whoopsy! new normal | `POST /checks/{check_id}/whoopsy/reset` |
| which channels would be notified | `POST /channels/preview` |
| replay failed deliveries in bulk | `POST /channels/{channel_id}/deliveries/replay` |
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

## 9. A restored account starts empty

If an account's monitoring was removed on the monitoring service, the first customer call after that restores it automatically and **empties it**: the old hosts, checks, channels and sites are deleted, so the customer starts from a clean account (checks that used to run, and be billed, do not come back). The first call can take a few seconds longer. After it, `GET /hosts` returns an empty list and the first-run "add your first host" state applies. A suspended account is not restored; it gets `409 tenant-deleted` until it is unsuspended. The check that notices a deleted tenant runs at most every five minutes per account, so a delete can show up as one or two plain `404 not-found` answers before the restore happens; treat that like any `404` and refresh.
