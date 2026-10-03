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
