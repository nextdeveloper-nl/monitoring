<?php

namespace NextDeveloper\Monitoring\Drivers;

use DateTimeInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use DateTimeImmutable;
use NextDeveloper\Monitoring\Contracts\ManagesChecks;
use NextDeveloper\Monitoring\DataTransferObjects\Alert;
use NextDeveloper\Monitoring\DataTransferObjects\Check;
use NextDeveloper\Monitoring\DataTransferObjects\CheckResult;
use NextDeveloper\Monitoring\DataTransferObjects\CheckState;
use NextDeveloper\Monitoring\Enums\CheckStatus;
use NextDeveloper\Monitoring\DataTransferObjects\Event;
use NextDeveloper\Monitoring\DataTransferObjects\Host;
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
 * Supports tenants, hosts (devices) and checks. Metrics, alerts and push throw UnsupportedOperation until the server ships them.
 */
class PlusCloudsDriver extends AbstractDriver implements ManagesChecks
{
    protected array $capabilities = ['tenants', 'hosts', 'checks'];

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
            ($data['status'] ?? 'active') === 'suspended' ? TenantStatus::Suspended : TenantStatus::Active,
            $data,
        );
    }

    /** Tenant-scoped calls act in the tenant named by its external id (the account UUID). */
    protected function tenantHeaders(string $tenantId): array
    {
        return ['X-Tenant-External-ID' => $tenantId];
    }

    /** Hosts are devices on the monitoring server. Filters: type, site_id, parent_id, tag (k=v). */
    public function listHosts(string $tenantId, array $filters = []): Collection
    {
        $hosts = collect();
        $cursor = null;

        do {
            $page = $this->request('GET', $this->path('devices'), [], array_filter($filters + ['limit' => 500, 'cursor' => $cursor]), $this->tenantHeaders($tenantId));

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
            $page = $this->request('GET', $this->path($path), [], array_filter($filters + ['limit' => 500, 'cursor' => $cursor], fn ($v) => $v !== null), $this->tenantHeaders($tenantId));

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

    public function getMetrics(string $tenantId, string $hostId, array $keys = [], ?DateTimeInterface $from = null, ?DateTimeInterface $to = null): Collection
    {
        $this->unsupported('getMetrics');
    }

    public function listAlerts(string $tenantId, array $filters = []): Collection
    {
        $this->unsupported('listAlerts');
    }

    public function acknowledgeAlert(string $tenantId, string $alertId, ?string $note = null): Alert
    {
        $this->unsupported('acknowledgeAlert');
    }

    public function resolveAlert(string $tenantId, string $alertId): Alert
    {
        $this->unsupported('resolveAlert');
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
