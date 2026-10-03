<?php

namespace NextDeveloper\Monitoring\Tests\Feature;

use NextDeveloper\Monitoring\Drivers\NullDriver;
use NextDeveloper\Monitoring\Exceptions\DriverNotConfigured;
use NextDeveloper\Monitoring\Facades\Monitoring;
use NextDeveloper\Monitoring\Models\MonitoringServer;
use NextDeveloper\Monitoring\Models\MonitoringTenant;
use NextDeveloper\Monitoring\Tests\Fakes\FakeDriver;
use NextDeveloper\Monitoring\Tests\TestCase;

class ManagerTest extends TestCase
{
    private function server(string $driver = 'null', array $attrs = []): MonitoringServer
    {
        return MonitoringServer::create($attrs + ['name' => uniqid('s'), 'driver' => $driver, 'base_url' => 'https://x.test']);
    }

    public function test_resolves_driver_from_server(): void
    {
        $this->assertInstanceOf(NullDriver::class, Monitoring::forServer($this->server()));
    }

    public function test_default_server_driver(): void
    {
        $this->server('null', ['is_default' => true]);

        $this->assertSame('null', Monitoring::driver()->driverName());
        $this->assertTrue(Monitoring::listHosts('t')->isEmpty());
    }

    public function test_no_default_server_throws(): void
    {
        $this->expectException(DriverNotConfigured::class);
        Monitoring::driver();
    }

    public function test_unknown_driver_throws(): void
    {
        $this->expectException(DriverNotConfigured::class);
        Monitoring::forServer($this->server('nope'));
    }

    public function test_register_custom_driver_and_for_tenant(): void
    {
        Monitoring::register('fake', FakeDriver::class);
        $server = $this->server('fake');
        $tenant = MonitoringTenant::create([
            'iam_account_id' => 1, 'monitoring_server_id' => $server->id, 'external_tenant_id' => 'r1', 'name' => 'n',
        ]);

        $this->assertSame('fake', Monitoring::forTenant($tenant)->driverName());
    }
}
