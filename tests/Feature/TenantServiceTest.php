<?php

namespace NextDeveloper\Monitoring\Tests\Feature;

use Illuminate\Support\Facades\DB;
use NextDeveloper\Monitoring\Enums\TenantStatus;
use NextDeveloper\Monitoring\Facades\Monitoring;
use NextDeveloper\Monitoring\Models\MonitoringServer;
use NextDeveloper\Monitoring\Models\MonitoringTenant;
use NextDeveloper\Monitoring\Services\TenantService;
use NextDeveloper\Monitoring\Tests\Fakes\FakeDriver;
use NextDeveloper\Monitoring\Tests\TestCase;

class TenantServiceTest extends TestCase
{
    private MonitoringServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        FakeDriver::reset();
        Monitoring::register('fake', FakeDriver::class);
        $this->server = MonitoringServer::create(['name' => 's', 'driver' => 'fake', 'base_url' => 'https://x.test', 'is_default' => true]);
    }

    public function test_create_persists_mapping_after_remote_success(): void
    {
        $tenant = app(TenantService::class)->create(1, 'Acme');

        $this->assertSame('remote-1', $tenant->external_tenant_id);
        $this->assertSame($this->server->id, $tenant->monitoring_server_id);
        $this->assertSame(['remote-1'], FakeDriver::$created);
    }

    public function test_create_is_idempotent_per_account_and_server(): void
    {
        $a = app(TenantService::class)->create(1, 'Acme');
        $b = app(TenantService::class)->create(1, 'Acme');

        $this->assertTrue($a->is($b));
        $this->assertCount(1, FakeDriver::$created);
    }

    public function test_remote_tenant_removed_when_local_persist_fails(): void
    {
        DB::unprepared('ALTER TABLE monitoring_tenants ADD CONSTRAINT always_fail CHECK (false) NOT VALID');

        try {
            app(TenantService::class)->create(1, 'Acme');
            $this->fail('expected exception');
        } catch (\Throwable) {
        }

        $this->assertSame(['remote-1'], FakeDriver::$deleted);
    }

    public function test_suspend_and_delete(): void
    {
        $service = app(TenantService::class);
        $tenant = $service->create(1, 'Acme');

        $this->assertSame(TenantStatus::Suspended, $service->suspend($tenant)->status);
        $service->delete($tenant);

        $this->assertSame(['remote-1'], FakeDriver::$deleted);
        $this->assertSame(0, MonitoringTenant::count());
    }

    public function test_failed_remote_delete_keeps_local_row(): void
    {
        $service = app(TenantService::class);
        $tenant = $service->create(1, 'Acme');
        FakeDriver::$failDelete = true;

        try {
            $service->delete($tenant);
        } catch (\RuntimeException) {
        }

        $this->assertSame(1, MonitoringTenant::count());
    }
}
