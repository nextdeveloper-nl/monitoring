<?php

namespace NextDeveloper\Monitoring\Tests\Feature;

use Illuminate\Support\Facades\Crypt;
use NextDeveloper\Monitoring\Facades\Monitoring;
use NextDeveloper\Monitoring\Models\MonitoringServer;
use NextDeveloper\Monitoring\Models\MonitoringTenant;
use NextDeveloper\Monitoring\Services\DefaultChannelService;
use NextDeveloper\Monitoring\Tests\Fakes\FakeDriver;
use NextDeveloper\Monitoring\Tests\TestCase;

class DefaultChannelServiceTest extends TestCase
{
    private MonitoringTenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        FakeDriver::reset();
        Monitoring::register('fake', FakeDriver::class);
        $server = MonitoringServer::create(['name' => 's', 'driver' => 'fake', 'base_url' => 'https://x.test', 'is_default' => true]);
        $this->tenant = MonitoringTenant::create([
            'iam_account_id' => 1, 'monitoring_server_id' => $server->getKey(), 'external_tenant_id' => 'acc-1', 'name' => 'Acme', 'status' => 'active',
        ]);
    }

    public function test_disabled_without_a_url_and_does_nothing(): void
    {
        config(['monitoring.default_channel.url' => null]);
        $service = app(DefaultChannelService::class);

        $this->assertFalse($service->enabled());
        $service->ensure($this->tenant, Monitoring::driver('fake'));
        $this->assertArrayNotHasKey('default_channel', $this->tenant->fresh()->meta ?? []);
    }

    public function test_secret_is_kept_encrypted_and_can_be_read_and_forgotten(): void
    {
        $this->tenant->update(['meta' => ['default_channel' => ['webhook_id' => 'w', 'secret' => Crypt::encryptString('whsec_abc')]]]);
        $service = app(DefaultChannelService::class);

        $this->assertNotSame('whsec_abc', $this->tenant->fresh()->meta['default_channel']['secret']);
        $this->assertSame('whsec_abc', $service->secretFor('acc-1'));
        $this->assertNull($service->secretFor('unknown'));

        $service->forget($this->tenant->fresh());
        $this->assertNull($service->secretFor('acc-1'));
    }
}
