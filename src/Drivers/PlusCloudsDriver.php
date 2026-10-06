<?php

namespace NextDeveloper\Monitoring\Drivers;

use DateTimeInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use DateTimeImmutable;
use DateTimeZone;
use NextDeveloper\Monitoring\Contracts\ChecksConnection;
use NextDeveloper\Monitoring\Contracts\ListsMetricSeries;
use NextDeveloper\Monitoring\Contracts\ListsPlugins;
use NextDeveloper\Monitoring\Contracts\ListsCheckObjects;
use NextDeveloper\Monitoring\Contracts\ManagesChecks;
use NextDeveloper\Monitoring\Contracts\ManagesCredentials;
use NextDeveloper\Monitoring\Contracts\RestoresTenants;
use NextDeveloper\Monitoring\Contracts\SummarizesMetrics;
use NextDeveloper\Monitoring\Contracts\ManagesMembers;
use NextDeveloper\Monitoring\Contracts\ReportsUsage;
use NextDeveloper\Monitoring\Contracts\ManagesNotifications;
use NextDeveloper\Monitoring\Contracts\ManagesSites;
use NextDeveloper\Monitoring\Contracts\ManagesWhoopsy;
use NextDeveloper\Monitoring\DataTransferObjects\Alert;
use NextDeveloper\Monitoring\DataTransferObjects\AlertRoute;
use NextDeveloper\Monitoring\DataTransferObjects\Site;
use NextDeveloper\Monitoring\DataTransferObjects\Webhook;
use NextDeveloper\Monitoring\DataTransferObjects\Check;
use NextDeveloper\Monitoring\DataTransferObjects\CheckResult;
use NextDeveloper\Monitoring\DataTransferObjects\CheckState;
use NextDeveloper\Monitoring\DataTransferObjects\Credential;
use NextDeveloper\Monitoring\Enums\AlertSeverity;
use NextDeveloper\Monitoring\Enums\AlertStatus;
use NextDeveloper\Monitoring\Enums\CheckStatus;
use NextDeveloper\Monitoring\DataTransferObjects\Event;
use NextDeveloper\Monitoring\DataTransferObjects\Host;
use NextDeveloper\Monitoring\DataTransferObjects\Metric;
use NextDeveloper\Monitoring\DataTransferObjects\MetricSeries;
use NextDeveloper\Monitoring\DataTransferObjects\Tenant;
use NextDeveloper\Monitoring\Enums\HostStatus;
use NextDeveloper\Monitoring\Exceptions\ApiRequestFailed;
use NextDeveloper\Monitoring\Enums\TenantStatus;

/**
 * Driver for the PlusClouds monitoring server (REST, /v1).
 *
 * Auth is a platform key (credentials.token) sent as a bearer token. Tenants are addressed by
 * external id, which is the account UUID, so in this driver the "tenant id" used in every call
 * is that external id (not the monitoring server's own tenant UUID).
 *
 * Supports tenants, hosts (devices), checks, alerts (incidents), sites, notifications (webhooks, alert routes) and stored check metrics (read).
 * Push throws UnsupportedOperation until the server ships them.
 */
class PlusCloudsDriver extends AbstractDriver implements ManagesChecks, ManagesSites, ManagesNotifications, ListsMetricSeries, ReportsUsage, ManagesMembers, ListsPlugins, ChecksConnection, RestoresTenants, ManagesCredentials, ListsCheckObjects, SummarizesMetrics, ManagesWhoopsy
{
    /** Set by actingAs(): the user whose role applies to tenant calls. Null = the platform, with full rights in the tenant. */
    protected ?string $actor = null;

    protected array $capabilities = ['tenants', 'hosts', 'checks', 'alerts', 'sites', 'notifications', 'metrics', 'usage', 'members', 'plugins', 'connection', 'restore', 'credentials', 'collectors', 'summary', 'whoopsy'];

    public function driverName(): string
    {
        return 'plusclouds';
    }

    /** Server routes live under /v1; keep it out of base_url so the host can be moved freely. */
    protected function path(string $path): string
    {
        return 'v1/'.ltrim($path, '/');
    }

    /**
     * Idempotent upsert. Requires $options['external_id'] (the account UUID);
     * optional: external_type, limits.
     */
    public function createTenant(string $name, array $options = []): Tenant
    {
        $externalId = $options['external_id'] ?? null;

        if (! $externalId) {
            throw new InvalidArgumentException('PlusClouds driver needs options[external_id] (account UUID) to create a tenant.');
        }

        $body = array_filter([
            'name' => $name,
            'external_type' => $options['external_type'] ?? null,
            'limits' => $options['limits'] ?? null,
        ], fn ($v) => $v !== null);

        return $this->toTenant($this->request('PUT', $this->tenantPath($externalId), $body), $externalId);
    }

    /** Read without writing: the server has no GET by external id, so filter the tenant list. Empty = not found. */
    public function getTenant(string $tenantId): Tenant
    {
        $page = $this->request('GET', $this->path('tenants'), [], ['external_source' => 'plusclouds', 'external_id' => $tenantId]);

        if (empty($page['items'][0])) {
            throw new ApiRequestFailed("Tenant [{$tenantId}] not found on monitoring server [{$this->server->name}].", 404, $page);
        }

        return $this->toTenant($page['items'][0], $tenantId);
    }

    public function suspendTenant(string $tenantId): Tenant
    {
        return $this->setStatus($tenantId, 'suspended');
    }

    public function resumeTenant(string $tenantId): Tenant
    {
        return $this->setStatus($tenantId, 'active');
    }

    /** Soft delete on the server; a deleted external id cannot be reused (409 tenant-deleted). */
    public function deleteTenant(string $tenantId): void
    {
        $this->request('DELETE', $this->tenantPath($tenantId));
    }

    /** Undo a soft delete (platform key). The tenant must not have been purged yet, or the server answers 404. */
    public function restoreTenant(string $tenantId): Tenant
    {
        return $this->toTenant($this->request('POST', $this->tenantPath($tenantId).'/restore'), $tenantId);
    }

    protected function setStatus(string $tenantId, string $status): Tenant
    {
        return $this->toTenant($this->request('PATCH', $this->tenantPath($tenantId), ['status' => $status]), $tenantId);
    }

    protected function tenantPath(string $externalId): string
    {
        return $this->path('tenants/by-external-id/'.rawurlencode($externalId));
    }

    protected function toTenant(array $data, string $externalId): Tenant
    {
        return new Tenant(
            $externalId,
            (string) ($data['name'] ?? $externalId),
            match ($data['status'] ?? 'active') {
                'suspended' => TenantStatus::Suspended,
                'deleted' => TenantStatus::Deleted,
                default => TenantStatus::Active,
            },
            $data,
        );
    }

    /** Query parameters: null is dropped, booleans become the true/false the service expects (a plain array_filter would drop false). */
    protected function query(array $params): array
    {
        $out = [];

        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $out[$key] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
        }

        return $out;
    }

    /** Tenant-scoped calls act in the tenant named by its external id (the account UUID). */
    protected function tenantHeaders(string $tenantId): array
    {
        return array_filter(['X-Tenant-External-ID' => $tenantId, 'X-Actor-External-ID' => $this->actor]);
    }

    public function actingAs(?string $userExternalId): static
    {
        $copy = clone $this;
        $copy->actor = $userExternalId;

        return $copy;
    }

    /**
     * The monitoring service addresses members by its own tenant id, not the external id we use everywhere else,
     * so look it up first. Platform call: no tenant or actor header.
     */
    public function upsertMember(string $tenantId, string $userExternalId, string $role): void
    {
        $this->request('PUT', $this->path("tenants/{$this->serverTenantId($tenantId)}/members/by-external-id/".rawurlencode($userExternalId)), ['role' => $role]);
    }

    public function removeMember(string $tenantId, string $userExternalId): void
    {
        $this->request('DELETE', $this->path("tenants/{$this->serverTenantId($tenantId)}/members/by-external-id/".rawurlencode($userExternalId)));
    }

    protected function serverTenantId(string $externalId): string
    {
        $id = $this->getTenant($externalId)->raw['id'] ?? null;

        return $id ?: throw new ApiRequestFailed("Tenant [{$externalId}] has no id on monitoring server [{$this->server->name}].", 404);
    }

    /** Hosts are devices on the monitoring server. Filters: type, site_id, parent_id, tag (k=v). */
    public function listHosts(string $tenantId, array $filters = []): Collection
    {
        $hosts = collect();
        $cursor = null;

        do {
            $page = $this->request('GET', $this->path('devices'), [], $this->query($filters + ['limit' => 500, 'cursor' => $cursor]), $this->tenantHeaders($tenantId));

            foreach ($page['items'] ?? [] as $device) {
                $hosts->push($this->toHost($device));
            }

            $cursor = $page['next_cursor'] ?? null;
        } while ($cursor);

        return $hosts;
    }

    public function getHost(string $tenantId, string $hostId): Host
    {
        return $this->toHost($this->request('GET', $this->path("devices/{$hostId}"), [], [], $this->tenantHeaders($tenantId)));
    }

    /** Needs $host->type (a DeviceType, e.g. server, vm). Extra fields (site_id, notes, parent_id, external) go in $host->raw. */
    public function createHost(string $tenantId, Host $host): Host
    {
        if (! $host->type) {
            throw new InvalidArgumentException('PlusCloudsDriver needs Host::$type (network, server, vm, ...) to create a host.');
        }

        // With an external id (the source object's UUID) use the idempotent upsert, so a retry returns the same device.
        if ($host->externalId) {
            $path = $this->path('devices/by-external-id/'.rawurlencode($host->externalId));

            return $this->toHost($this->request('PUT', $path, $this->deviceBody($host), array_filter(['type' => $host->externalType]), $this->tenantHeaders($tenantId)));
        }

        return $this->toHost($this->request('POST', $this->path('devices'), $this->deviceBody($host), [], $this->tenantHeaders($tenantId)));
    }

    /** JSON Merge Patch: omitted fields stay, null clears, tags merge key by key. */
    public function updateHost(string $tenantId, string $hostId, array $attributes): Host
    {
        $body = array_intersect_key($attributes, array_flip(['name', 'type', 'address', 'tags', 'notes', 'parent_id', 'site_id', 'physical_peer_id']));

        return $this->toHost($this->request('PATCH', $this->path("devices/{$hostId}"), $body, [], $this->tenantHeaders($tenantId)));
    }

    /** confirm=true so a host that contains others (and its checks) is removed too. */
    public function deleteHost(string $tenantId, string $hostId): void
    {
        $this->request('DELETE', $this->path("devices/{$hostId}"), [], ['confirm' => 'true'], $this->tenantHeaders($tenantId));
    }

    protected function deviceBody(Host $host): array
    {
        return array_filter([
            'name' => $host->name,
            'type' => $host->type,
            'address' => $host->address,
            'tags' => $host->tags ?: null,
            'notes' => $host->raw['notes'] ?? null,
            'parent_id' => $host->raw['parent_id'] ?? null,
            'site_id' => $host->raw['site_id'] ?? null,
            'physical_peer_id' => $host->raw['physical_peer_id'] ?? null,
            'external' => $host->externalId ? null : ($host->raw['external'] ?? null),
        ], fn ($v) => $v !== null);
    }

    /** Availability comes from the device's host check; unmonitored and unknown both map to unknown. */
    protected function toHost(array $device): Host
    {
        return new Host(
            $device['id'] ?? null,
            (string) ($device['name'] ?? ''),
            $device['address'] ?? null,
            match ($device['status']['availability'] ?? null) {
                'up' => HostStatus::Up,
                'down' => HostStatus::Down,
                'disabled' => HostStatus::Disabled,
                default => HostStatus::Unknown,
            },
            $device['tags'] ?? [],
            $device,
            $device['type'] ?? null,
            $device['external']['id'] ?? null,
            $device['external']['type'] ?? null,
        );
    }

    /** All checks of the tenant, or those of one host (server filters: plugin, enabled). Follows cursors. */
    public function listChecks(string $tenantId, ?string $hostId = null, array $filters = []): Collection
    {
        $checks = collect();
        $cursor = null;
        $path = $hostId ? "devices/{$hostId}/checks" : 'checks';

        do {
            $page = $this->request('GET', $this->path($path), [], $this->query($filters + ['limit' => 500, 'cursor' => $cursor]), $this->tenantHeaders($tenantId));

            foreach ($page['items'] ?? [] as $item) {
                $checks->push($this->toCheck($item));
            }

            $cursor = $page['next_cursor'] ?? null;
        } while ($cursor);

        return $checks;
    }

    public function getCheck(string $tenantId, string $checkId): Check
    {
        return $this->toCheck($this->request('GET', $this->path("checks/{$checkId}"), [], [], $this->tenantHeaders($tenantId)));
    }

    public function createCheck(string $tenantId, Check $check): Check
    {
        if (! $check->hostId) {
            throw new InvalidArgumentException('PlusCloudsDriver needs Check::$hostId to create a check.');
        }

        $body = array_filter([
            'name' => $check->name,
            'plugin' => $check->plugin,
            'config' => $check->config ?: null,
            'interval_seconds' => $check->intervalSeconds,
            'enabled' => $check->enabled,
            'thresholds' => $check->thresholds ?: null,
            'is_host_check' => $check->isHostCheck,
            // failure_count, recovery_count, timeout_seconds, runbook_url, credentials: pass via $check->raw.
        ] + array_intersect_key($check->raw, array_flip(['timeout_seconds', 'failure_count', 'recovery_count', 'unknown_is_critical', 'runbook_url', 'credentials'])), fn ($v) => $v !== null);

        return $this->toCheck($this->request('POST', $this->path("devices/{$check->hostId}/checks"), $body, [], $this->tenantHeaders($tenantId)));
    }

    /** JSON Merge Patch; the plugin of a check cannot change (409). */
    public function updateCheck(string $tenantId, string $checkId, array $attributes): Check
    {
        $allowed = ['name', 'config', 'interval_seconds', 'timeout_seconds', 'enabled', 'thresholds', 'failure_count', 'recovery_count', 'is_host_check', 'unknown_is_critical', 'runbook_url', 'credentials'];

        return $this->toCheck($this->request('PATCH', $this->path("checks/{$checkId}"), array_intersect_key($attributes, array_flip($allowed)), [], $this->tenantHeaders($tenantId)));
    }

    public function deleteCheck(string $tenantId, string $checkId): void
    {
        $this->request('DELETE', $this->path("checks/{$checkId}"), [], [], $this->tenantHeaders($tenantId));
    }

    /** 404 "no-state" before the first result is not an error here: returns null. */
    public function getCheckState(string $tenantId, string $checkId): ?CheckState
    {
        try {
            $s = $this->request('GET', $this->path("checks/{$checkId}/state"), [], [], $this->tenantHeaders($tenantId));
        } catch (ApiRequestFailed $e) {
            if ($e->status === 404 && str_ends_with((string) data_get($e->body, 'type'), '/no-state')) {
                return null;
            }

            throw $e;
        }

        return new CheckState(
            $checkId,
            (string) ($s['phase'] ?? 'OK'),
            CheckStatus::fromServer($s['status'] ?? null),
            $s['last_output'] ?? null,
            $s['last_metrics'] ?? [],
            isset($s['since']) ? new DateTimeImmutable($s['since']) : null,
            isset($s['last_result_at']) ? new DateTimeImmutable($s['last_result_at']) : null,
            $s['incident_id'] ?? null,
            $s,
        );
    }

    public function runCheckNow(string $tenantId, string $checkId): void
    {
        $this->request('POST', $this->path("checks/{$checkId}/run-now"), [], [], $this->tenantHeaders($tenantId));
    }

    /** Runs every enabled check once (server allows up to 60 s); stores nothing. */
    public function testHost(string $tenantId, string $hostId): Collection
    {
        $results = $this->request('POST', $this->path("devices/{$hostId}/test"), [], [], $this->tenantHeaders($tenantId));

        // The spec returns a bare array; request() decodes it as such.
        return collect($results['items'] ?? $results)->map(fn (array $r) => new CheckResult(
            (string) ($r['check_id'] ?? ''),
            (string) ($r['name'] ?? ''),
            (string) ($r['plugin'] ?? ''),
            CheckStatus::fromServer($r['status'] ?? null),
            $r['output'] ?? null,
            isset($r['duration_ms']) ? (int) round($r['duration_ms']) : null,
            $r['metrics'] ?? [],
            $r,
            $r['objects'] ?? [],
        ))->values();
    }

    protected function toCheck(array $c): Check
    {
        return new Check(
            $c['id'] ?? null,
            $c['device_id'] ?? null,
            (string) ($c['name'] ?? ''),
            (string) ($c['plugin'] ?? ''),
            $c['config'] ?? [],
            $c['interval_seconds'] ?? null,
            (bool) ($c['enabled'] ?? true),
            $c['thresholds'] ?? [],
            (bool) ($c['is_host_check'] ?? false),
            $c,
        );
    }

    /**
     * Stored check metrics of one host (device). $keys are metric names; options: check_id, object, step (s),
     * agg (avg|min|max|sum|stddev|p<number> such as p95 or p99.9; the last two need resolution raw, which exists for about 7 days), moving_window (1..1000, mean of the last N points), resolution (raw|5m|1h). Defaults on the server: last hour, ~500 points.
     * Buckets with no data are omitted, so a gap means no data. Another tenant's ids come back empty.
     */
    public function getMetrics(string $tenantId, string $hostId, array $keys = [], ?DateTimeInterface $from = null, ?DateTimeInterface $to = null, array $options = []): Collection
    {
        $query = array_filter([
            'device_id' => $hostId,
            'check_id' => $options['check_id'] ?? null,
            'object' => $options['object'] ?? null,
            'from' => $from?->format(DATE_ATOM),
            'to' => $to?->format(DATE_ATOM),
            'step' => $options['step'] ?? null,
            'agg' => $options['agg'] ?? null,
            'resolution' => $options['resolution'] ?? null,
            'moving_window' => $options['moving_window'] ?? null,
        ], fn ($v) => $v !== null);

        $response = $this->request('GET', $this->path('metrics/query').$this->queryString($query, ['name' => $keys]), [], [], $this->tenantHeaders($tenantId));

        return collect($response['series'] ?? [])->map(fn (array $s) => new MetricSeries(
            (string) ($s['name'] ?? ''),
            collect($s['points'] ?? [])->map(fn (array $p) => new Metric(
                (string) ($s['name'] ?? ''),
                $p['v'],
                new DateTimeImmutable($p['t']),
            ))->values(),
            $s['unit'] ?? null,
            $s['object'] ?? null,
            array_diff_key($s, ['points' => 1]) + ['resolution' => $response['resolution'] ?? null, 'step' => $response['step'] ?? null],
        ))->values();
    }

    /**
     * Statistics over the whole window per series: count, min, max, avg, stddev, p50, p95, p99, extra percentiles, last value.
     * Computed from raw samples, so they are exact; a window older than raw retention is refused with 422, never approximated.
     * Options: check_id, object, percentile (string[], for example p90 and p99.9), window (statistics of the last N samples too).
     */
    public function summarizeMetrics(string $tenantId, string $hostId, array $keys = [], ?DateTimeInterface $from = null, ?DateTimeInterface $to = null, array $options = []): array
    {
        $query = $this->query([
            'device_id' => $hostId,
            'check_id' => $options['check_id'] ?? null,
            'object' => $options['object'] ?? null,
            'from' => $from?->format(DATE_ATOM),
            'to' => $to?->format(DATE_ATOM),
            'window' => $options['window'] ?? null,
        ]);

        $response = $this->request('GET', $this->path('metrics/summary').$this->queryString($query, ['name' => $keys, 'percentile' => $options['percentile'] ?? []]), [], [], $this->tenantHeaders($tenantId));

        return [
            'resolution' => $response['resolution'] ?? null,
            'exact' => (bool) ($response['exact'] ?? false),
            'from' => $response['from'] ?? null,
            'to' => $response['to'] ?? null,
            'series' => collect($response['series'] ?? [])->values(),
        ];
    }

    /** What is stored for a host and/or check: id, plugin, object, name, unit, kind, retention_class. At most 200. */
    public function listMetricSeries(string $tenantId, ?string $hostId = null, ?string $checkId = null, array $keys = [], ?string $object = null): Collection
    {
        if (! $hostId && ! $checkId) {
            throw new InvalidArgumentException('listMetricSeries needs a host id or a check id.');
        }

        $query = $this->query(['device_id' => $hostId, 'check_id' => $checkId, 'object' => $object]);
        $response = $this->request('GET', $this->path('metrics/series').$this->queryString($query, ['name' => $keys]), [], [], $this->tenantHeaders($tenantId));

        return collect($response['items'] ?? [])->values();
    }

    /** "?a=1&name=x&name=y": the server wants repeated keys, not name[0]=x. */
    protected function queryString(array $single, array $repeated = []): string
    {
        $parts = [http_build_query($single)];

        foreach ($repeated as $key => $values) {
            foreach ($values as $value) {
                $parts[] = rawurlencode($key).'='.rawurlencode((string) $value);
            }
        }

        $parts = array_filter($parts, fn ($p) => $p !== '');

        return $parts ? '?'.implode('&', $parts) : '';
    }

    /** Alerts are incidents. Filters: status (open|acknowledged|resolved|active), severity, device_id (or host_id), check_id, object_key, suppressed (bool). */
    public function listAlerts(string $tenantId, array $filters = []): Collection
    {
        if (isset($filters['host_id'])) {
            $filters['device_id'] = $filters['host_id'];
            unset($filters['host_id']);
        }

        $alerts = collect();
        $cursor = null;

        do {
            $page = $this->request('GET', $this->path('incidents'), [], $this->query($filters + ['limit' => 500, 'cursor' => $cursor]), $this->tenantHeaders($tenantId));

            foreach ($page['items'] ?? [] as $incident) {
                $alerts->push($this->toAlert($incident));
            }

            $cursor = $page['next_cursor'] ?? null;
        } while ($cursor);

        return $alerts;
    }

    /** Acknowledging twice is a no-op on the server; a note is added as a comment. */
    public function acknowledgeAlert(string $tenantId, string $alertId, ?string $note = null): Alert
    {
        $incident = $this->request('POST', $this->path("incidents/{$alertId}/ack"), [], [], $this->tenantHeaders($tenantId));

        if ($note !== null && $note !== '') {
            $this->request('POST', $this->path("incidents/{$alertId}/comments"), ['body' => $note], [], $this->tenantHeaders($tenantId));
        }

        return $this->toAlert($incident);
    }

    /** The check's state starts over; if the problem persists a new incident opens. */
    public function resolveAlert(string $tenantId, string $alertId): Alert
    {
        return $this->toAlert($this->request('POST', $this->path("incidents/{$alertId}/resolve"), [], [], $this->tenantHeaders($tenantId)));
    }

    protected function toAlert(array $i): Alert
    {
        return new Alert(
            (string) ($i['id'] ?? ''),
            (string) ($i['summary'] ?? ''),
            AlertSeverity::tryFrom($i['severity'] ?? '') ?? AlertSeverity::Warning,
            AlertStatus::tryFrom($i['status'] ?? '') ?? AlertStatus::Open,
            $i['device_id'] ?? null,
            isset($i['opened_at']) ? new DateTimeImmutable($i['opened_at']) : null,
            $i,
        );
    }

    /**
     * Billable usage per tenant per closed hour (platform key, no tenant header). Items carry the account UUID,
     * hour start/end, billable_check_seconds (already weighted), device_seconds (informational, not billed),
     * revision (only the latest per hour is returned) and the per-plugin breakdown.
     * The server refuses open hours and ranges over 31 days with 422; whole-hour bounds are checked here too.
     */
    public function usage(DateTimeInterface $from, DateTimeInterface $to): Collection
    {
        $utc = new DateTimeZone('UTC');
        $from = DateTimeImmutable::createFromInterface($from)->setTimezone($utc);
        $to = DateTimeImmutable::createFromInterface($to)->setTimezone($utc);

        foreach ([$from, $to] as $bound) {
            if ($bound->format('i:s') !== '00:00') {
                throw new InvalidArgumentException('usage() needs whole UTC hours; got '.$bound->format(DATE_ATOM).'.');
            }
        }

        $items = collect();
        $cursor = null;

        do {
            $page = $this->request('GET', $this->path('usage/tenants'), [], array_filter([
                'from' => $from->format('Y-m-d\TH:i:s\Z'),
                'to' => $to->format('Y-m-d\TH:i:s\Z'),
                'limit' => 500,
                'cursor' => $cursor,
            ]));

            foreach ($page['items'] ?? [] as $item) {
                $items->push($item);
            }

            $cursor = $page['next_cursor'] ?? null;
        } while ($cursor);

        return $items;
    }

    /**
     * A platform-level read (the tenant list, one row), so it proves both reachability and that the platform key is accepted.
     * A wrong or tenant-level key is rejected by the server (401/403).
     */
    public function checkConnection(): void
    {
        $this->request('GET', $this->path('tenants'), [], ['limit' => 1]);
    }

    /** Plugin manifests as the server serves them. Not paginated; the list is short. */
    public function listPlugins(string $tenantId): Collection
    {
        return collect($this->request('GET', $this->path('plugins'), [], [], $this->tenantHeaders($tenantId))['items'] ?? [])->values();
    }

    /** Follow cursors for any list endpoint, mapping each item. */
    protected function listAll(string $tenantId, string $path, callable $map): Collection
    {
        $items = collect();
        $cursor = null;

        do {
            $page = $this->request('GET', $this->path($path), [], array_filter(['limit' => 500, 'cursor' => $cursor]), $this->tenantHeaders($tenantId));

            foreach ($page['items'] ?? [] as $item) {
                $items->push($map($item));
            }

            $cursor = $page['next_cursor'] ?? null;
        } while ($cursor);

        return $items;
    }

    /** Upsert by external id (the source object's UUID); source defaults to plusclouds on the server. */
    protected function upsertByExternalId(string $tenantId, string $resource, ?string $externalId, ?string $externalType, array $body): array
    {
        if (! $externalId) {
            throw new InvalidArgumentException("PlusCloudsDriver needs an externalId to upsert a {$resource}.");
        }

        return $this->request('PUT', $this->path("{$resource}/by-external-id/".rawurlencode($externalId)), $body, array_filter(['type' => $externalType]), $this->tenantHeaders($tenantId));
    }

    public function listSites(string $tenantId): Collection
    {
        return $this->listAll($tenantId, 'sites', fn (array $s) => $this->toSite($s));
    }

    public function upsertSite(string $tenantId, Site $site): Site
    {
        $body = array_filter([
            'name' => $site->name,
            'country' => $site->country,
            'timezone' => $site->timezone,
            'address' => $site->address,
        ], fn ($v) => $v !== null);

        return $this->toSite($this->upsertByExternalId($tenantId, 'sites', $site->externalId, $site->externalType, $body));
    }

    public function deleteSite(string $tenantId, string $siteId): void
    {
        $this->request('DELETE', $this->path("sites/{$siteId}"), [], [], $this->tenantHeaders($tenantId));
    }

    protected function toSite(array $s): Site
    {
        return new Site($s['id'] ?? null, (string) ($s['name'] ?? ''), $s['country'] ?? null, $s['timezone'] ?? null, $s['address'] ?? null, $s['external']['id'] ?? null, $s['external']['type'] ?? null, $s);
    }

    public function listWebhooks(string $tenantId): Collection
    {
        return $this->listAll($tenantId, 'webhooks', fn (array $w) => $this->toWebhook($w));
    }

    public function upsertWebhook(string $tenantId, Webhook $webhook): Webhook
    {
        $body = array_filter([
            'name' => $webhook->name,
            'url' => $webhook->url,
            'enabled' => $webhook->enabled,
            'timeout_seconds' => $webhook->timeoutSeconds,
            'headers' => $webhook->headers ?: null,
        ], fn ($v) => $v !== null);

        return $this->toWebhook($this->upsertByExternalId($tenantId, 'webhooks', $webhook->externalId, $webhook->externalType, $body));
    }

    public function deleteWebhook(string $tenantId, string $webhookId): void
    {
        $this->request('DELETE', $this->path("webhooks/{$webhookId}"), [], [], $this->tenantHeaders($tenantId));
    }

    public function testWebhook(string $tenantId, string $webhookId): array
    {
        $r = $this->request('POST', $this->path("webhooks/{$webhookId}/test"), [], [], $this->tenantHeaders($tenantId));

        return ['ok' => (bool) ($r['ok'] ?? false), 'status_code' => $r['status_code'] ?? null, 'response' => $r['response'] ?? null, 'error' => $r['error'] ?? null];
    }

    public function rotateWebhookSecret(string $tenantId, string $webhookId): string
    {
        $r = $this->request('POST', $this->path("webhooks/{$webhookId}/rotate-secret"), [], [], $this->tenantHeaders($tenantId));

        return (string) ($r['secret'] ?? '');
    }

    /** Newest first; follows cursors but stops at 500 rows, the list can be long. */
    public function listWebhookDeliveries(string $tenantId, string $webhookId, ?string $status = null): Collection
    {
        $items = collect();
        $cursor = null;

        do {
            $page = $this->request('GET', $this->path("webhooks/{$webhookId}/deliveries"), [], array_filter(['limit' => 100, 'cursor' => $cursor, 'status' => $status]), $this->tenantHeaders($tenantId));
            $items = $items->merge($page['items'] ?? []);
            $cursor = $page['next_cursor'] ?? null;
        } while ($cursor && $items->count() < 500);

        return $items->values();
    }

    public function replayWebhookDelivery(string $tenantId, string $webhookId, string $deliveryId): void
    {
        $this->request('POST', $this->path("webhooks/{$webhookId}/deliveries/{$deliveryId}/replay"), [], [], $this->tenantHeaders($tenantId));
    }

    /** The secret is in the response only when the webhook was created or rotated; raw keeps it out of toArray(). */
    protected function toWebhook(array $w): Webhook
    {
        return new Webhook($w['id'] ?? null, (string) ($w['name'] ?? ''), (string) ($w['url'] ?? ''), (bool) ($w['enabled'] ?? true), $w['timeout_seconds'] ?? null, [], $w['external']['id'] ?? null, $w['external']['type'] ?? null, $w['secret'] ?? null, array_diff_key($w, ['secret' => 1]), $w['disabled_reason'] ?? null);
    }

    public function listAlertRoutes(string $tenantId): Collection
    {
        return $this->listAll($tenantId, 'alert-routes', fn (array $r) => $this->toAlertRoute($r));
    }

    public function upsertAlertRoute(string $tenantId, AlertRoute $route): AlertRoute
    {
        $body = array_filter([
            'name' => $route->name,
            'endpoint_id' => $route->webhookId,
            'position' => $route->position,
            'enabled' => $route->enabled,
            'match' => $route->match ?: null,
            'continue' => $route->continue,
            'labels' => $route->labels ?: null,
            'group_by' => $route->groupBy ?: null,
            'group_wait_seconds' => $route->groupWaitSeconds,
            'repeat_interval_seconds' => $route->repeatIntervalSeconds,
            'steps' => $route->steps ?: null,
        ], fn ($v) => $v !== null);

        return $this->toAlertRoute($this->upsertByExternalId($tenantId, 'alert-routes', $route->externalId, $route->externalType, $body));
    }

    public function deleteAlertRoute(string $tenantId, string $routeId): void
    {
        $this->request('DELETE', $this->path("alert-routes/{$routeId}"), [], [], $this->tenantHeaders($tenantId));
    }

    protected function toAlertRoute(array $r): AlertRoute
    {
        return new AlertRoute($r['id'] ?? null, (string) ($r['name'] ?? ''), (string) ($r['endpoint_id'] ?? ''), $r['position'] ?? null, (bool) ($r['enabled'] ?? true), $r['match'] ?? [], (bool) ($r['continue'] ?? false), $r['labels'] ?? [], $r['external']['id'] ?? null, $r['external']['type'] ?? null, $r, $r['group_by'] ?? [], $r['group_wait_seconds'] ?? null, $r['repeat_interval_seconds'] ?? null, $r['steps'] ?? []);
    }

    public function replayWebhookDeliveries(string $tenantId, string $webhookId, DateTimeInterface $from, DateTimeInterface $to, string $status = 'failed'): void
    {
        $this->request('POST', $this->path("webhooks/{$webhookId}/deliveries/replay").'?'.http_build_query($this->query([
            'status' => $status,
            'from' => DateTimeImmutable::createFromInterface($from)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z'),
            'to' => DateTimeImmutable::createFromInterface($to)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z'),
        ])), [], [], $this->tenantHeaders($tenantId));
    }

    public function previewAlertRoutes(string $tenantId, array $sample): Collection
    {
        $response = $this->request('POST', $this->path('alert-routes/test'), $sample, [], $this->tenantHeaders($tenantId));

        return collect($response['routes'] ?? [])->values();
    }

    public function listCredentialTypes(string $tenantId): Collection
    {
        return collect($this->request('GET', $this->path('credential-types'), [], [], $this->tenantHeaders($tenantId))['items'] ?? [])->values();
    }

    public function listCredentials(string $tenantId): Collection
    {
        return $this->listAll($tenantId, 'credentials', fn (array $c) => $this->toCredential($c));
    }

    public function getCredential(string $tenantId, string $credentialId): Credential
    {
        return $this->toCredential($this->request('GET', $this->path("credentials/{$credentialId}"), [], [], $this->tenantHeaders($tenantId)));
    }

    public function createCredential(string $tenantId, Credential $credential): Credential
    {
        return $this->toCredential($this->request('POST', $this->path('credentials'), $this->credentialBody($credential), [], $this->tenantHeaders($tenantId)));
    }

    /** PUT replaces; a secret field that is not sent keeps its stored value, so callers send only the secrets they change. */
    public function updateCredential(string $tenantId, string $credentialId, Credential $credential): Credential
    {
        return $this->toCredential($this->request('PUT', $this->path("credentials/{$credentialId}"), $this->credentialBody($credential), [], $this->tenantHeaders($tenantId)));
    }

    public function deleteCredential(string $tenantId, string $credentialId): void
    {
        $this->request('DELETE', $this->path("credentials/{$credentialId}"), [], [], $this->tenantHeaders($tenantId));
    }

    protected function credentialBody(Credential $c): array
    {
        return ['name' => $c->name, 'type' => $c->type, 'fields' => (object) $c->fields];
    }

    /** Never copies anything secret: the server only says which secret fields are set. */
    protected function toCredential(array $c): Credential
    {
        return new Credential($c['id'] ?? null, (string) ($c['name'] ?? ''), (string) ($c['type'] ?? ''), $c['fields'] ?? [], $c['secrets_set'] ?? [], array_diff_key($c, ['fields' => 1]));
    }

    public function listCheckObjects(string $tenantId, string $checkId, ?string $status = null, bool $includeGone = true): Collection
    {
        $response = $this->request('GET', $this->path("checks/{$checkId}/objects"), [], $this->query(['status' => $status, 'include_gone' => $includeGone]), $this->tenantHeaders($tenantId));

        return collect($response['items'] ?? [])->values();
    }

    public function getWhoopsy(string $tenantId, string $checkId): array
    {
        return $this->request('GET', $this->path("checks/{$checkId}/whoopsy"), [], [], $this->tenantHeaders($tenantId));
    }

    public function setWhoopsy(string $tenantId, string $checkId, array $settings): array
    {
        // Turning it on with no settings uses the service defaults; an empty body would be dropped, so name the default window.
        return $this->request('PUT', $this->path("checks/{$checkId}/whoopsy"), $settings ?: ['window' => 7], [], $this->tenantHeaders($tenantId));
    }

    public function disableWhoopsy(string $tenantId, string $checkId): void
    {
        $this->request('DELETE', $this->path("checks/{$checkId}/whoopsy"), [], [], $this->tenantHeaders($tenantId));
    }

    public function resetWhoopsy(string $tenantId, string $checkId): array
    {
        return $this->request('POST', $this->path("checks/{$checkId}/whoopsy/reset"), [], [], $this->tenantHeaders($tenantId));
    }

    public function whoopsyPricing(string $tenantId): array
    {
        $w = $this->request('GET', $this->path('usage/weights'), [], [], $this->tenantHeaders($tenantId));

        return [
            'multiplier' => (int) ($w['whoopsy_multiplier'] ?? 1),
            'default_weight' => (int) ($w['default_weight'] ?? 1),
            'weights' => $w['weights'] ?? [],
        ];
    }

    public function pushMetrics(string $tenantId, string $hostId, iterable $metrics): void
    {
        $this->unsupported('pushMetrics');
    }

    public function pushEvent(string $tenantId, Event $event): void
    {
        $this->unsupported('pushEvent');
    }
}
