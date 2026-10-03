<?php

namespace NextDeveloper\Monitoring;

use Closure;
use Illuminate\Contracts\Container\Container;
use NextDeveloper\Monitoring\Contracts\MonitoringDriver;
use NextDeveloper\Monitoring\Exceptions\DriverNotConfigured;
use NextDeveloper\Monitoring\Models\MonitoringServer;
use NextDeveloper\Monitoring\Models\MonitoringTenant;

/**
 * Builds drivers from MonitoringServer rows. Driver classes are looked up by MonitoringServer::driver.
 */
class MonitoringManager
{
    /** @var array<string, Closure(MonitoringServer): MonitoringDriver> */
    protected array $factories = [];

    /** @var array<string, MonitoringDriver> */
    protected array $resolved = [];

    public function __construct(protected Container $container)
    {
    }

    /** Register a driver by class name or factory closure. */
    public function register(string $name, string|Closure $driver): static
    {
        $this->factories[$name] = $driver instanceof Closure
            ? $driver
            : fn (MonitoringServer $server) => $this->container->make($driver, ['server' => $server]);

        unset($this->resolved[$name]);

        return $this;
    }

    public function forServer(MonitoringServer $server): MonitoringDriver
    {
        $key = $server->getKey() ?? spl_object_id($server);
        $cacheKey = $server->driver.':'.$key.':'.($server->updated_at?->getTimestamp() ?? 0);

        return $this->resolved[$cacheKey] ??= $this->build($server);
    }

    public function forTenant(MonitoringTenant $tenant): MonitoringDriver
    {
        return $this->forServer($tenant->server);
    }

    /** Default server: configured name, else is_default row. */
    public function defaultServer(): MonitoringServer
    {
        $query = MonitoringServer::query()->where('is_active', true);

        $name = config('monitoring.default_server');
        $server = $name
            ? (clone $query)->where('name', $name)->first()
            : (clone $query)->where('is_default', true)->first();

        return $server ?? throw DriverNotConfigured::noServer();
    }

    public function driver(): MonitoringDriver
    {
        return $this->forServer($this->defaultServer());
    }

    public function __call(string $method, array $parameters): mixed
    {
        return $this->driver()->$method(...$parameters);
    }

    protected function build(MonitoringServer $server): MonitoringDriver
    {
        $factory = $this->factories[$server->driver] ?? null;

        if ($factory === null) {
            $class = config("monitoring.drivers.{$server->driver}");
            $class || throw DriverNotConfigured::unknown($server->driver);
            $factory = fn (MonitoringServer $s) => $this->container->make($class, ['server' => $s]);
        }

        return $factory($server);
    }
}
