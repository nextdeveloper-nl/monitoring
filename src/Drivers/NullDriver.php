<?php

namespace NextDeveloper\Monitoring\Drivers;

use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use NextDeveloper\Monitoring\DataTransferObjects\Alert;
use NextDeveloper\Monitoring\DataTransferObjects\Event;
use NextDeveloper\Monitoring\DataTransferObjects\Host;
use NextDeveloper\Monitoring\DataTransferObjects\Tenant;
use NextDeveloper\Monitoring\Enums\TenantStatus;

/** No-op driver for disabled environments. Reads are empty, writes are swallowed. */
class NullDriver extends AbstractDriver
{
    public function driverName(): string
    {
        return 'null';
    }

    public function createTenant(string $name, array $options = []): Tenant
    {
        return new Tenant((string) Str::uuid(), $name);
    }

    public function getTenant(string $tenantId): Tenant
    {
        return new Tenant($tenantId, $tenantId);
    }

    public function suspendTenant(string $tenantId): Tenant
    {
        return new Tenant($tenantId, $tenantId, TenantStatus::Suspended);
    }

    public function resumeTenant(string $tenantId): Tenant
    {
        return new Tenant($tenantId, $tenantId);
    }

    public function deleteTenant(string $tenantId): void
    {
    }

    public function listHosts(string $tenantId, array $filters = []): Collection
    {
        return collect();
    }

    public function getHost(string $tenantId, string $hostId): Host
    {
        return new Host($hostId, $hostId);
    }

    public function createHost(string $tenantId, Host $host): Host
    {
        return new Host($host->id ?? (string) Str::uuid(), $host->name, $host->address, $host->status, $host->tags);
    }

    public function updateHost(string $tenantId, string $hostId, array $attributes): Host
    {
        return new Host($hostId, $attributes['name'] ?? $hostId, $attributes['address'] ?? null);
    }

    public function deleteHost(string $tenantId, string $hostId): void
    {
    }

    public function getMetrics(string $tenantId, string $hostId, array $keys = [], ?DateTimeInterface $from = null, ?DateTimeInterface $to = null): Collection
    {
        return collect();
    }

    public function listAlerts(string $tenantId, array $filters = []): Collection
    {
        return collect();
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
    }

    public function pushEvent(string $tenantId, Event $event): void
    {
    }
}
