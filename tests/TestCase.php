<?php

namespace NextDeveloper\Monitoring\Tests;

use Illuminate\Support\Facades\DB;
use NextDeveloper\Monitoring\MonitoringServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Needs a PostgreSQL database. Set MONITORING_TEST_DB_{HOST,PORT,DATABASE,USERNAME,PASSWORD}.
 * Tables are dropped and recreated from schemas/*.sql for every test.
 */
abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [MonitoringServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => env('MONITORING_TEST_DB_HOST', '127.0.0.1'),
            'port' => env('MONITORING_TEST_DB_PORT', 5432),
            'database' => env('MONITORING_TEST_DB_DATABASE', 'monitoring_test'),
            'username' => env('MONITORING_TEST_DB_USERNAME', 'postgres'),
            'password' => env('MONITORING_TEST_DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'sslmode' => 'prefer',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        DB::unprepared('DROP TABLE IF EXISTS monitoring_tenants, monitoring_servers CASCADE');

        foreach (['monitoring_servers', 'monitoring_tenants'] as $table) {
            DB::unprepared(file_get_contents(__DIR__."/../schemas/{$table}.sql"));
        }
    }

    protected function tearDown(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS monitoring_tenants, monitoring_servers CASCADE');

        parent::tearDown();
    }
}
