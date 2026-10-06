<?php

namespace NextDeveloper\Monitoring\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use NextDeveloper\Monitoring\Contracts\ChecksConnection;
use NextDeveloper\Monitoring\Contracts\RestoresTenants;
use NextDeveloper\Monitoring\Exceptions\MonitoringException;
use NextDeveloper\Monitoring\Models\MonitoringServer;
use NextDeveloper\Monitoring\MonitoringManager;
use Throwable;

/**
 * Registers monitoring servers: the connection (URL and platform credentials) our system uses to reach one
 * monitoring service instance. Platform administration, not customer-facing: customers never see or pick a server.
 *
 * Credentials are write-only. They are stored encrypted by the model cast and never returned, only whether any are set.
 * Exactly one active server is the default; new tenants are created on it. Changing the default does not move tenants
 * that already exist on another server.
 */
class MonitoringServerService
{
    public function __construct(private readonly MonitoringManager $manager) {}

    /** @return array<int, array<string, mixed>> */
    public function list(): array
    {
        return MonitoringServer::query()->withCount('tenants')->orderBy('id')->get()
            ->map(fn (MonitoringServer $s) => $this->present($s))->all();
    }

    public function get(string $uuid): array
    {
        return $this->present($this->find($uuid));
    }

    /**
     * $data: name, driver, base_url, credentials{token}, is_default?, is_active?, options?
     * The first active server becomes the default automatically.
     */
    public function create(array $data): array
    {
        $this->assertDriver($data['driver']);
        $this->assertNameFree($data['name']);

        $server = DB::transaction(function () use ($data) {
            $isActive = $data['is_active'] ?? true;
            $hasDefault = MonitoringServer::query()->where('is_active', true)->where('is_default', true)->exists();
            $isDefault = $isActive && (($data['is_default'] ?? false) || ! $hasDefault);

            if ($isDefault) {
                MonitoringServer::query()->where('is_default', true)->update(['is_default' => false]);
            }

            return MonitoringServer::create([
                'name' => $data['name'],
                'driver' => $data['driver'],
                'base_url' => rtrim($data['base_url'], '/'),
                'credentials' => $data['credentials'],
                'options' => $data['options'] ?? null,
                'is_default' => $isDefault,
                'is_active' => $isActive,
            ]);
        });

        // The database generates the uuid, so reload to get it.
        return $this->present($server->refresh()->loadCount('tenants'));
    }

    /** Partial update. Omit `credentials` to keep the stored ones; send it to replace them. */
    public function update(string $uuid, array $data): array
    {
        $server = $this->find($uuid);

        if (isset($data['driver'])) {
            $this->assertDriver($data['driver']);
        }

        if (isset($data['name']) && $data['name'] !== $server->name) {
            $this->assertNameFree($data['name']);
        }

        DB::transaction(function () use ($server, $data) {
            if (! empty($data['is_default']) && ($data['is_active'] ?? $server->is_active)) {
                MonitoringServer::query()->where('id', '!=', $server->id)->where('is_default', true)->update(['is_default' => false]);
            }

            $changes = array_intersect_key($data, array_flip(['name', 'driver', 'base_url', 'credentials', 'options', 'is_default', 'is_active']));

            if (isset($changes['base_url'])) {
                $changes['base_url'] = rtrim($changes['base_url'], '/');
            }

            // A server that is switched off cannot stay the default.
            if (array_key_exists('is_active', $changes) && ! $changes['is_active']) {
                $changes['is_default'] = false;
            }

            $server->update($changes);
        });

        return $this->present($server->fresh()->loadCount('tenants'));
    }

    /** Refused while accounts still have a tenant on this server: they would lose their monitoring. */
    public function delete(string $uuid): void
    {
        $server = $this->find($uuid);

        if ($server->tenants()->exists()) {
            throw new \DomainException('This server still has tenants. Move or remove them first, or switch the server off instead.');
        }

        $server->delete();
    }

    /**
     * Try the connection with the stored credentials. Never throws for a failed connection: that is the answer.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function test(string $uuid): array
    {
        $server = $this->find($uuid);

        try {
            $driver = $this->manager->forServer($server);

            if (! $driver instanceof ChecksConnection) {
                return ['ok' => false, 'error' => 'This driver cannot test the connection.'];
            }

            $driver->checkConnection();

            return ['ok' => true, 'error' => null];
        } catch (MonitoringException $e) {
            // Includes a rejected key (401/403) and an unreachable host; the message names the server, not the key.
            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'The connection test failed.'];
        }
    }

    /**
     * Bring back a tenant that was deleted on this server (platform key), before it is purged. The tenant becomes active
     * with its devices, checks and history; checks start again. Fails with 404 once it was purged.
     *
     * @return array{status: string}
     */
    public function restoreTenant(string $serverUuid, string $accountUuid): array
    {
        $server = $this->find($serverUuid);
        $driver = $this->manager->forServer($server);

        if (! $driver instanceof RestoresTenants) {
            throw new \InvalidArgumentException('This driver cannot restore tenants.');
        }

        $tenant = $driver->restoreTenant($accountUuid);
        Cache::forget("monitoring:tenant-verified:{$accountUuid}");

        return ['status' => $tenant->status->value];
    }

    private function find(string $uuid): MonitoringServer
    {
        return MonitoringServer::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function assertDriver(string $driver): void
    {
        if (! array_key_exists($driver, config('monitoring.drivers', []))) {
            throw new \InvalidArgumentException("Unknown driver [{$driver}]. Available: ".implode(', ', array_keys(config('monitoring.drivers', []))).'.');
        }
    }

    private function assertNameFree(string $name): void
    {
        if (MonitoringServer::query()->where('name', $name)->exists()) {
            throw new \DomainException("A monitoring server named [{$name}] already exists.");
        }
    }

    /** @return array<string, mixed> never includes the credentials */
    private function present(MonitoringServer $s): array
    {
        return [
            'id' => $s->uuid,
            'name' => $s->name,
            'driver' => $s->driver,
            'base_url' => $s->base_url,
            'is_default' => (bool) $s->is_default,
            'is_active' => (bool) $s->is_active,
            'has_credentials' => ! empty($s->credentials),
            'tenants' => (int) ($s->tenants_count ?? $s->tenants()->count()),
            'created_at' => $s->created_at?->toAtomString(),
        ];
    }
}
