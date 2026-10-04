<?php

return [
    /*
     * Monitoring server used when a tenant is created without an explicit server.
     * Null = the server flagged is_default in the monitoring_servers table.
     */
    'default_server' => env('MONITORING_DEFAULT_SERVER'),

    'tables' => [
        'servers' => 'monitoring_servers',
        'tenants' => 'monitoring_tenants',
    ],

    /*
     * Driver name => driver class. Drivers are built from a MonitoringServer row,
     * so connection details live in the database, not here.
     * Register more with Monitoring::register('name', Class::class).
     */
    'drivers' => [
        'null' => \NextDeveloper\Monitoring\Drivers\NullDriver::class,
        'plusclouds' => \NextDeveloper\Monitoring\Drivers\PlusCloudsDriver::class,
    ],

    'http' => [
        'timeout' => env('MONITORING_HTTP_TIMEOUT', 15),
        'retries' => env('MONITORING_HTTP_RETRIES', 2),
        'retry_delay_ms' => env('MONITORING_HTTP_RETRY_DELAY_MS', 200),
    ],
];
