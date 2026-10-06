# Monitoring library design

`nextdeveloper/monitoring` is a Laravel library that gives one API over several monitoring services (PlusClouds monitoring server, Zabbix, and others). Each service is a driver. The first real driver is PlusClouds; Zabbix follows later.

## Principles

- **Read live, store little.** The database holds only monitoring server connections and tenant mappings. Hosts, metrics and alerts are never stored; they are always read from the monitoring server.
- **Tenant per account.** When an account is created, a tenant is created in the monitoring service. The local row maps the account to that remote tenant.
- **Swappable drivers.** Drivers implement one contract and are built from a server row, so several instances of the same service can coexist.

## Data model

PostgreSQL, following the NextDeveloper convention of SQL files in `schemas/` (bigint `id` plus `uuid`, `timestamptz`, `deleted_at`). The host application applies them: `monitoring_servers.sql` first, then `monitoring_tenants.sql`.

- `monitoring_servers`: `name`, `driver`, `base_url`, `credentials` (encrypted by the model cast), `options` json, `is_default`, `is_active`.
- `monitoring_tenants`: `iam_account_id`, `monitoring_server_id`, `external_tenant_id` (the tenant id in the monitoring service), `name`, `status`, `meta` json. Unique per server and external tenant id.

## Components

- `MonitoringManager`: registers drivers (`register(name, class|closure)`), resolves a driver from a server (`forServer`) or tenant (`forTenant`), and falls back to the default server (`driver()`). Unknown drivers throw `DriverNotConfigured`.
- `Contracts\MonitoringDriver`: the union of `ManagesTenants`, `ManagesHosts`, `ReadsMetrics`, `ManagesAlerts` and `PushesData`, plus `driverName()` and `supports($capability)`. A driver that lacks an operation throws `UnsupportedOperation`.
- `Drivers\AbstractDriver`: base for HTTP drivers. Builds the HTTP client from the server row (base URL, token, timeout, retry on connection errors) and maps failures to `ApiRequestFailed`.
- `Drivers\NullDriver`: no-op driver for disabled environments.
- `Services\TenantService`: tenant lifecycle. The remote tenant is created first, then the local row. If saving fails, the remote tenant is deleted again. Creating twice for the same account and server returns the existing tenant. Deleting removes the remote tenant first, so a failed remote delete keeps the local row.
- `DataTransferObjects` (`Tenant`, `Host`, `Metric`, `MetricSeries`, `Alert`, `Event`) and `Enums`: provider-neutral value types. DTOs keep the provider payload in `raw`.
- `Facades\Monitoring`, `MonitoringServiceProvider` (auto-discovered; publishes config and schemas).

## Usage

```php
Monitoring::register('plusclouds', PlusCloudsDriver::class);

$tenant = app(TenantService::class)->create($iamAccountId, 'Acme');
$hosts  = Monitoring::forTenant($tenant)->listHosts($tenant->external_tenant_id);
```

## Testing

Tests use Orchestra Testbench against PostgreSQL. Set `MONITORING_TEST_DB_{HOST,PORT,DATABASE,USERNAME,PASSWORD}`; tables are recreated from `schemas/*.sql` for every test. `tests/Fakes/FakeDriver` is reusable by consumers.

## PlusClouds driver (next)

Target: the PlusClouds monitoring server (Go, REST, OpenAPI 3.1; spec at `/v1/openapi.json`). At milestone M1 it offers tenants, users and memberships, API keys, audit log and plugin manifests. Devices, checks, metrics and incidents arrive in later milestones.

- Auth: a platform key (stored encrypted in the server row's `credentials`) with `X-Tenant-External-ID` set to the account UUID on each call. `X-Actor-External-ID` optionally acts as a user.
- Tenants map to `PUT/PATCH/DELETE /v1/tenants/by-external-id/{id}`. Suspend and resume are a status patch; delete is a soft delete, and a deleted external id cannot be reused (409 `tenant-deleted`).
- Errors are RFC 9457 problem documents; branch on the `type` field.
- Until the server ships devices and metrics, the driver supports only `tenants`; other operations throw `UnsupportedOperation`.
- `AbstractDriver::request()` needs per-call headers for the tenant and actor.
- Tests: `Http::fake` unit tests, plus an optional live suite gated by environment variables, using a fresh external id per run.

## Not covered yet

Server API keys, audit log, members and plugin manifests are outside the current contract; they can become optional capability interfaces later.

## HTTP API (v0.3.0)

The module serves the monitoring HTTP API itself, in the same style as the IAM module: `src/Http/api.routes.php` is registered by the service provider (switch off with `leo.allowed_routes.monitoring = false`) and served under `/monitoring`. Controllers are per resource (`Hosts`, `Checks`, `Alerts`, `Channels`, `Sites`, `Plugins`, `Tenant`, `Servers`) and only validate and delegate to `MonitoringProxyService` and `MonitoringServerService`.

- Customer endpoints act for the logged-in user's current account (the tenant is created on first use) and make the user a tenant member with a role mapped from their account role.
- `/monitoring/servers` is platform administration for `monitoring-admin` and `system-admin`; credentials are write-only.
- The controllers use `NextDeveloper\IAM\Helpers\UserHelper` for the current user, account and roles, so the module expects the IAM package in the host application.
- Not in the module: the glue to other modules (account suspension, billing usage emitter) stays in the host application.
- Route caching: the provider does not register routes when they are cached, so clear the route cache after upgrading the module.

The API reference for UI developers: `docs/monitoring-ui-api.md` (customer), `docs/monitoring-servers-admin-api.md` (admin), `docs/monitoring-webhooks.md` (receiving alerts).

## Roles

Three roles, one class each in `src/Authorization/Roles`, following the S3 and DNS modules:

| Role | Level | Meaning |
| --- | --- | --- |
| `monitoring-admin` | 100 | the monitoring service owner: manages monitoring servers, sees every tenant. Never granted to customers |
| `monitoring-manager` | 150 | operator within the account: configures hosts, checks, sites and channels, acknowledges alerts |
| `monitoring-user` | 200 | read-only within the account |

Customer data (hosts, checks, alerts) lives on the monitoring service, not in our database, so the table permissions in the roles only cover `monitoring_servers` and `monitoring_tenants`. What a customer may do is decided by `MonitoringProxyService`: users holding any role in `monitoring.operator_roles` become **operator** members of their tenant, everybody else is **read-only**; the monitoring service enforces it. `monitoring.admin_roles` decides who may call `/monitoring/servers`.

Account owners get `monitoring-manager` and every user gets `monitoring-user` (host application: `register.owner_roles`, `register.default_roles`; existing users are backfilled with `leo:assign-monitoring-roles`). The role rows must exist in `iam_roles` (create them with `RolesService::getRole()` or `leo:generate-roles` in the host application) and the host application decides who gets them (`register.default_roles`, `owner_roles`).

### Route authorization (important)

The host application's global `Authorize` middleware maps a URL of at most two segments to `<module>_<object>:<operation>` (`GET /monitoring/hosts` is `monitoring_hosts:read`, `POST /monitoring/hosts` is `monitoring_hosts:create`, `/monitoring/tenant` is `monitoring_tenant:read`) and answers 403 unless one of the user's roles lists it. So **a user needs a monitoring role to reach any of these URLs, and every new top-level route needs its operations added to the roles' `allowedOperations()`.** URLs with three or more segments (`/monitoring/hosts/{id}`, `.../checks`) are not checked there; the monitoring service enforces the member role on those. `/monitoring/servers/{id}` is protected by the controller (`monitoring.admin_roles`).

### Tenant health

The local `monitoring_tenants` row is created once and trusted afterwards, but the monitoring service can soft-delete or purge a tenant. `MonitoringProxyService` therefore checks the tenant on the service every five minutes:

- **deleted**: restored automatically and then emptied (`TenantRecoveryService` deletes its routes, webhooks, hosts with their checks, and sites), so nothing from before comes back or is billed. The host application can veto the restore by binding `GuardsTenantRestore` (the PlusClouds app refuses for suspended accounts); a vetoed or unsupported restore answers `409 tenant-deleted`. One request restores at a time (cache lock); `meta.wipe_pending` on the local row makes the next call finish emptying if it failed midway. Credentials and past incident history are not touched.
- **gone** (purged, or the service was reset): created again, empty.
- Administrators can restore a deleted tenant as it was (with its content) with `POST /monitoring/servers/{server_id}/tenants/{account_id}/restore`.

Every failed monitoring request is logged with the upstream call, status, problem type and request id.
