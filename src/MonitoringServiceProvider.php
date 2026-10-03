<?php

namespace NextDeveloper\Monitoring;

use Illuminate\Support\ServiceProvider;

class MonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/monitoring.php', 'monitoring');

        $this->app->singleton(MonitoringManager::class, fn ($app) => new MonitoringManager($app));
        $this->app->alias(MonitoringManager::class, 'monitoring');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/monitoring.php' => config_path('monitoring.php')], 'monitoring-config');
            $this->publishes([__DIR__.'/../schemas' => base_path('schemas/monitoring')], 'monitoring-schemas');
        }
    }
}
