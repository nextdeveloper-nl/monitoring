<?php

/*
 * Monitoring module routes, served under /monitoring (registered by MonitoringServiceProvider, like the IAM module).
 *
 * Customer endpoints act for the logged-in user's current account: the tenant is that account, created on first use.
 * /monitoring/servers is platform administration (system administrators only).
 */
Route::prefix('monitoring')->group(
    function () {
        Route::get('tenant', 'Tenant\TenantController@show');
        Route::get('plugins', 'Plugins\PluginsController@index');

        Route::get('credential-types', 'Credentials\CredentialsController@types');
        Route::prefix('credentials')->group(
            function () {
                Route::get('/', 'Credentials\CredentialsController@index');
                Route::post('/', 'Credentials\CredentialsController@store');

                Route::patch('{credential_id}', 'Credentials\CredentialsController@update');
                Route::delete('{credential_id}', 'Credentials\CredentialsController@destroy');
            }
        );

        // MQTT ingest: credentials devices connect with, message profiles, dropped (unregistered) device keys
        Route::get('mqtt-profiles', 'Mqtt\MqttController@profiles');
        Route::get('mqtt-unregistered', 'Mqtt\MqttController@unregistered');
        Route::prefix('mqtt-credentials')->group(
            function () {
                Route::get('/', 'Mqtt\MqttController@index');
                Route::post('/', 'Mqtt\MqttController@store');

                Route::get('{credential_id}', 'Mqtt\MqttController@show');
                Route::patch('{credential_id}', 'Mqtt\MqttController@update');
                Route::delete('{credential_id}', 'Mqtt\MqttController@destroy');
                Route::post('{credential_id}/rotate', 'Mqtt\MqttController@rotate');
            }
        );

        Route::prefix('hosts')->group(
            function () {
                Route::get('/', 'Hosts\HostsController@index');
                Route::post('/', 'Hosts\HostsController@store');

                Route::get('{host_id}', 'Hosts\HostsController@show');
                Route::patch('{host_id}', 'Hosts\HostsController@update');
                Route::delete('{host_id}', 'Hosts\HostsController@destroy');
                Route::post('{host_id}/test', 'Hosts\HostsController@test');
                Route::get('{host_id}/mqtt', 'Mqtt\MqttController@showHost');
                Route::post('{host_id}/mqtt', 'Mqtt\MqttController@bindHost');
                Route::delete('{host_id}/mqtt', 'Mqtt\MqttController@unbindHost');

                Route::get('{host_id}/checks', 'Checks\ChecksController@forHost');
                Route::post('{host_id}/checks', 'Checks\ChecksController@storeForHost');

                Route::get('{host_id}/metrics', 'Metrics\HostMetricsController@index');
                Route::get('{host_id}/metrics/series', 'Metrics\HostMetricsController@series');
                Route::get('{host_id}/metrics/summary', 'Metrics\HostMetricsController@summary');
            }
        );

        Route::prefix('checks')->group(
            function () {
                Route::get('/', 'Checks\ChecksController@index');

                Route::get('{check_id}', 'Checks\ChecksController@show');
                Route::patch('{check_id}', 'Checks\ChecksController@update');
                Route::delete('{check_id}', 'Checks\ChecksController@destroy');
                Route::get('{check_id}/state', 'Checks\ChecksController@state');
                Route::get('{check_id}/objects', 'Checks\ChecksController@objects');
                Route::get('{check_id}/whoopsy', 'Whoopsy\WhoopsyController@show');
                Route::put('{check_id}/whoopsy', 'Whoopsy\WhoopsyController@update');
                Route::delete('{check_id}/whoopsy', 'Whoopsy\WhoopsyController@destroy');
                Route::post('{check_id}/whoopsy/reset', 'Whoopsy\WhoopsyController@reset');
                Route::get('{check_id}/whoopsy/band', 'Whoopsy\WhoopsyController@band');
                Route::post('{check_id}/run', 'Checks\ChecksController@run');
                Route::post('{check_id}/rotate-token', 'Checks\ChecksController@rotateToken');
            }
        );

        Route::prefix('alerts')->group(
            function () {
                Route::get('/', 'Alerts\AlertsController@index');

                Route::post('{alert_id}/acknowledge', 'Alerts\AlertsController@acknowledge');
                Route::post('{alert_id}/resolve', 'Alerts\AlertsController@resolve');
            }
        );

        Route::prefix('channels')->group(
            function () {
                Route::get('/', 'Channels\ChannelsController@index');
                Route::post('/', 'Channels\ChannelsController@store');

                Route::post('preview', 'Channels\ChannelsController@preview');

                Route::patch('{channel_id}', 'Channels\ChannelsController@update');
                Route::delete('{channel_id}', 'Channels\ChannelsController@destroy');
                Route::post('{channel_id}/test', 'Channels\ChannelsController@test');
                Route::post('{channel_id}/rotate-secret', 'Channels\ChannelsController@rotateSecret');
                Route::get('{channel_id}/deliveries', 'Channels\ChannelsController@deliveries');
                Route::post('{channel_id}/deliveries/replay', 'Channels\ChannelsController@replayAll');
                Route::post('{channel_id}/deliveries/{delivery_id}/replay', 'Channels\ChannelsController@replay');
            }
        );

        Route::prefix('sites')->group(
            function () {
                Route::get('/', 'Sites\SitesController@index');
                Route::post('/', 'Sites\SitesController@store');

                Route::delete('{site_id}', 'Sites\SitesController@destroy');
            }
        );

        Route::prefix('servers')->group(
            function () {
                Route::get('/', 'Servers\ServersController@index');
                Route::post('/', 'Servers\ServersController@store');

                Route::get('{server_id}', 'Servers\ServersController@show');
                Route::patch('{server_id}', 'Servers\ServersController@update');
                Route::delete('{server_id}', 'Servers\ServersController@destroy');
                Route::post('{server_id}/test', 'Servers\ServersController@test');
                Route::post('{server_id}/tenants/{account_id}/restore', 'Servers\ServersController@restoreTenant');
            }
        );
    }
);
