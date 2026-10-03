<?php

namespace NextDeveloper\Monitoring\Facades;

use Illuminate\Support\Facades\Facade;
use NextDeveloper\Monitoring\MonitoringManager;

/**
 * @method static \NextDeveloper\Monitoring\MonitoringManager register(string $name, string|\Closure $driver)
 * @method static \NextDeveloper\Monitoring\Contracts\MonitoringDriver forServer(\NextDeveloper\Monitoring\Models\MonitoringServer $server)
 * @method static \NextDeveloper\Monitoring\Contracts\MonitoringDriver forTenant(\NextDeveloper\Monitoring\Models\MonitoringTenant $tenant)
 * @method static \NextDeveloper\Monitoring\Contracts\MonitoringDriver driver()
 * @method static \NextDeveloper\Monitoring\Models\MonitoringServer defaultServer()
 *
 * @see MonitoringManager
 */
class Monitoring extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MonitoringManager::class;
    }
}
