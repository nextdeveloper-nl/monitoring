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
        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/monitoring.php' => config_path('monitoring.php')], 'monitoring-config');
            $this->publishes([__DIR__.'/../schemas' => base_path('schemas/monitoring')], 'monitoring-schemas');
        }
    }

    /**
     * Register the module routes. Switch off with leo.allowed_routes.monitoring = false.
     * Not registered when routes are cached: clear the route cache after upgrading this module.
     */
    protected function registerRoutes(): void
    {
        if (! $this->app->routesAreCached() && config('leo.allowed_routes.monitoring', true)) {
            $this->app['router']
                ->namespace('NextDeveloper\\Monitoring\\Http\\Controllers')
                ->group(__DIR__.DIRECTORY_SEPARATOR.'Http'.DIRECTORY_SEPARATOR.'api.routes.php');
        }
    }
}
