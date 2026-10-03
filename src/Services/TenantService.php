<?php

namespace NextDeveloper\Monitoring\Services;

use Illuminate\Support\Facades\Log;
use NextDeveloper\Monitoring\Enums\TenantStatus;
use NextDeveloper\Monitoring\MonitoringManager;
use NextDeveloper\Monitoring\Models\MonitoringServer;
use NextDeveloper\Monitoring\Models\MonitoringTenant;
use Throwable;

/**
 * Tenant lifecycle. Remote monitoring service is source of truth; local row only maps account -> remote tenant.
 */
class TenantService
{
    public function __construct(protected MonitoringManager $manager)
    {
    }

    public function create(int $iamAccountId, string $name, ?MonitoringServer $server = null, array $options = []): MonitoringTenant
    {
        $server ??= $this->manager->defaultServer();

        $existing = MonitoringTenant::query()
            ->where('iam_account_id', $iamAccountId)
            ->where('monitoring_server_id', $server->getKey())
            ->first();

        if ($existing) {
            return $existing;
        }

        $driver = $this->manager->forServer($server);
        $remote = $driver->createTenant($name, $options);

        try {
            return MonitoringTenant::create([
                'iam_account_id' => $iamAccountId,
                'monitoring_server_id' => $server->getKey(),
                'external_tenant_id' => $remote->id,
                'name' => $remote->name,
                'status' => $remote->status,
            ]);
        } catch (Throwable $e) {
            Log::error('['.static::class."] local persist failed, removing remote tenant {$remote->id}", ['error' => $e->getMessage()]);

            try {
                $driver->deleteTenant($remote->id);
            } catch (Throwable $cleanup) {
                Log::critical('['.static::class."] orphan remote tenant {$remote->id} on server {$server->name}", ['error' => $cleanup->getMessage()]);
            }

            throw $e;
        }
    }

    public function suspend(MonitoringTenant $tenant): MonitoringTenant
    {
        $this->manager->forTenant($tenant)->suspendTenant($tenant->external_tenant_id);
        $tenant->update(['status' => TenantStatus::Suspended]);

        return $tenant;
    }

    public function resume(MonitoringTenant $tenant): MonitoringTenant
    {
        $this->manager->forTenant($tenant)->resumeTenant($tenant->external_tenant_id);
        $tenant->update(['status' => TenantStatus::Active]);

        return $tenant;
    }

    public function delete(MonitoringTenant $tenant): void
    {
        $this->manager->forTenant($tenant)->deleteTenant($tenant->external_tenant_id);
        $tenant->delete();
    }
}
