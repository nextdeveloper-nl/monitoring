<?php

return [
    /*
     * Monitoring server used when a tenant is created without an explicit server.
     * Null = the server flagged is_default in the monitoring_servers table.
     */
    'default_server' => env('MONITORING_DEFAULT_SERVER'),

    /*
     * Roles that decide what a user may do in their account's monitoring tenant. Everything in operator_roles acts as
     * an operator (configure, acknowledge, run); everyone else is read-only. Nobody maps to the monitoring service's admin.
     */
    'operator_roles' => ['monitoring-manager', 'monitoring-admin'],

    /*
     * Roles that may manage monitoring servers (/monitoring/servers).
     */
    'admin_roles' => ['monitoring-admin', 'system-admin'],

    /*
     * A platform channel every account gets, so the platform receives all of its monitoring notifications and can act on
     * them (the receiver lives in the host application). Off unless a URL is set. `url` may contain {account_id}, which is
     * replaced with the account's id (the external tenant id), for example
     * https://apiv4.plusclouds.com/public/monitoring/notifications/{account_id}
     */
    'default_channel' => [
        'url' => env('MONITORING_DEFAULT_CHANNEL_URL'),
    ],

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
