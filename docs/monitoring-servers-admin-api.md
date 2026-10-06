# Monitoring servers: admin API

This is the contract for the admin screen where a platform administrator connects our system to a monitoring service. It is separate from the customer monitoring UI (`docs/monitoring-ui-api.md`). Customers never see or use it.

## 1. What a "monitoring server" is

Our system does not run the checks itself. A separate **monitoring service** (the `monitoring.server` software, for example at `https://monitoring.plusclouds.com`) runs them. A **monitoring server** in our system is only the saved **connection** to one such service: its address and the secret key our platform uses to talk to it.

Every customer account gets its own isolated tenant on the **default** server the first time it uses monitoring. Until a default, active server exists, every customer monitoring call fails with `503 monitoring-unavailable`. Creating that first server is therefore the first setup step of the whole feature.

**What this API does not do:** it does not install or start the monitoring software. Someone has to deploy a monitoring service instance first (that is an operations task, using the `monitoring.server` image) and create a **platform key** on it. This API then registers that instance. The screen should say so, and ask for the address and key of an instance that is already running.

## 2. Access

- Base path: `/monitoring/servers`, same API host and bearer login as the other API endpoints (for example `/iam`).
- Only users with the `system-admin` role. Everyone else gets `403 {"error": {"type": "forbidden", ...}}` on every call, before any validation. Do not show the screen to other users; if a call returns 403, hide it.
- Success bodies are `{"data": ...}`. Deletes return `204` with no body.
- Errors have the shape `{"error": {"type": "...", "message": "..."}}`, where `message` is safe to show. Field validation failures use the standard Laravel 422 body: `{"message": "...", "errors": {"field": ["..."]}}`.

## 3. The server object

```json
{
  "id": "416a4f85-141e-4375-b5ac-b37e68681873",
  "name": "plusclouds-monitoring",
  "driver": "plusclouds",
  "base_url": "https://monitoring.plusclouds.com",
  "is_default": true,
  "is_active": true,
  "has_credentials": true,
  "tenants": 12,
  "created_at": "2026-10-04T07:28:45+00:00"
}
```

- `id` is a UUID. Use it in the paths below as `{server_id}`.
- `driver` is the kind of monitoring service. Today the real one is `plusclouds`. (`null` also exists: it accepts everything and does nothing, for disabled environments. Do not offer it in production.)
- **The key is never returned.** `has_credentials` only says whether one is stored. After saving, there is no way to read it back, so never prefill a key field.
- `tenants` is the number of customer accounts living on this server.
- `is_default`: new tenants are created on the default server. At most one server is the default.
- `is_active`: an inactive server is skipped when choosing the default and cannot be the default.

## 4. Endpoints

### List and show

- `GET /monitoring/servers` returns `{"data": [server, ...]}` ordered by creation.
- `GET /monitoring/servers/{server_id}` returns one server, or `404`.

### Create

`POST /monitoring/servers`

```json
{
  "name": "plusclouds-monitoring",
  "driver": "plusclouds",
  "base_url": "https://monitoring.plusclouds.com",
  "credentials": {"token": "mon_xxxxxxxx_yyyyyyyyyyyyyyyy"},
  "is_default": true,
  "is_active": true
}
```

| Field | Rules |
| --- | --- |
| `name` | required, max 255, unique (a duplicate returns `409 conflict`) |
| `driver` | required, must be a known driver (an unknown one returns `422 invalid-value` listing the valid ones) |
| `base_url` | required, a full URL such as `https://monitoring.plusclouds.com`; a trailing slash is removed; do not include `/v1` |
| `credentials.token` | required, the platform key of the monitoring service (starts with `mon_`), 8 to 500 characters |
| `is_default` | optional boolean |
| `is_active` | optional boolean, default true |

Returns `201` with the server. **If no active default exists yet, the new server automatically becomes the default**, whatever `is_default` says. If `is_default` is true, any previous default is unset.

Recommended flow in the UI: submit the form, then immediately call **Test connection** (below) and show the result. If the test fails, keep the server but show the reason so the admin can edit it.

### Test the connection

`POST /monitoring/servers/{server_id}/test` (no body) always returns `200`. A failed connection is a normal answer, not an HTTP error:

```json
{"data": {"ok": true, "error": null}}
{"data": {"ok": false, "error": "Monitoring server [x] returned 401 for GET v1/tenants."}}
{"data": {"ok": false, "error": "Cannot reach monitoring server [x]."}}
```

- `ok: true` means the address is reachable and the key is accepted as a platform key.
- A 401 or 403 in the message means the key is wrong, or it is a customer key and not a platform key.
- "Cannot reach" means a wrong address, a network problem, or the service is down.
- The message never contains the key.

Show a green or red result with the message, and a "Test again" button.

### Update

`PATCH /monitoring/servers/{server_id}`: partial. Send only what changed.

- `name`, `driver`, `base_url`, `is_default`, `is_active`, `options` as in create.
- `credentials`: **omit it to keep the stored key.** To replace the key, send `{"credentials": {"token": "mon_..."}}`. Show the key field empty with a "Replace key" action, never prefilled.
- `is_default: true` makes this server the default and unsets the previous one in the same step.
- `is_active: false` also removes the default flag from this server. The UI should warn the admin that new tenants then have no default until another server is set.
- Changing the default does **not** move customers who already have a tenant on another server. They stay where they are.
- Returns the updated server.

### Delete

`DELETE /monitoring/servers/{server_id}` returns `204`. It is refused with `409 conflict` while the server still has tenants (`tenants` greater than 0), because those customers would lose their monitoring. Show the message and offer "Switch off" instead (`PATCH` with `is_active: false`).

## 5. Suggested screen

A table of servers with: name, address, driver, a Default badge, an Active toggle, tenants count, and per-row actions Test, Edit, Delete (disabled when `tenants > 0`, with a tooltip). A "Connect a monitoring server" button opens the create form. Above the table, when there is no active default server, show a prominent warning: "No default monitoring server. Customers cannot use monitoring until one is set up."

Create form fields: Name, Driver (select, `plusclouds`), Address, Platform key (password field, with the note "Shown only now; it cannot be read back"), "Make default" checkbox (checked and locked when it is the first server), Active.

## 6. Setting up the first server, step by step

1. An operator deploys a monitoring service instance and creates a platform key on it (outside this API).
2. A `system-admin` opens this screen and creates a server with that address and key.
3. Press Test connection. It must say `ok: true`.
4. Make sure it is the default and active.
5. Customers can now open the monitoring pages. Their tenants are created automatically on first use.

## 7. Things to know

- The key is stored encrypted. It cannot be recovered through any API, only replaced.
- Keys are sensitive: do not log them in the browser, do not keep them in state after submit, and do not put them in URLs.
- There is no endpoint yet to move a tenant to another server, to list a server's tenants, or to see the server's software version.
