<?php

namespace NextDeveloper\Monitoring\Tests\Feature;

use NextDeveloper\Monitoring\Contracts\ResolvesPushers;
use NextDeveloper\Monitoring\Exceptions\ApiRequestFailed;
use NextDeveloper\Monitoring\Facades\Monitoring;
use NextDeveloper\Monitoring\Models\MonitoringServer;
use NextDeveloper\Monitoring\Models\MonitoringTenant;
use NextDeveloper\Monitoring\Services\NotificationSettingsService;
use NextDeveloper\Monitoring\Tests\Fakes\FakeDriver;
use NextDeveloper\Monitoring\Tests\TestCase;

class NotificationSettingsServiceTest extends TestCase
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
            'meta' => ['default_channel' => ['webhook_id' => 'w']],
        ]);

        // the current user owns pusher "aaaaaaaa-..." only
        $this->app->bind(ResolvesPushers::class, fn () => new class implements ResolvesPushers {
            public function find(string $pusherId): ?array
            {
                return $pusherId === 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' ? ['id' => 7, 'uuid' => $pusherId, 'name' => 'My pusher'] : null;
            }

            public function describe(array $ids): array
            {
                return [];
            }
        });
    }

    public function test_defaults_send_nothing(): void
    {
        $service = app(NotificationSettingsService::class);

        $this->assertSame(['email' => false, 'in_app' => true, 'pusher_ids' => []], $service->forAccount('acc-1'));
        $this->assertSame(['email' => false, 'in_app' => true, 'pushers' => []], $service->show($this->tenant));
        $this->assertSame(['email' => false, 'in_app' => true, 'pusher_ids' => []], $service->forAccount('unknown'));
    }

    public function test_email_and_own_pushers_are_saved_per_account_and_other_meta_is_kept(): void
    {
        $service = app(NotificationSettingsService::class);

        $shown = $service->update($this->tenant, ['email' => true, 'in_app' => true, 'pusher_ids' => ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']]);

        $this->assertSame(['email' => true, 'in_app' => true, 'pushers' => [['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'name' => 'My pusher']]], $shown);
        $this->assertSame(['email' => true, 'in_app' => true, 'pusher_ids' => ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']], $service->forAccount('acc-1'));
        $this->assertSame('w', $this->tenant->fresh()->meta['default_channel']['webhook_id']);

        // a partial update leaves the other key alone
        $service->update($this->tenant->fresh(), ['email' => false]);
        $this->assertSame(['email' => false, 'in_app' => true, 'pusher_ids' => ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']], $service->forAccount('acc-1'));
    }

    public function test_in_app_is_on_by_default_and_can_be_switched_off_per_account(): void
    {
        $service = app(NotificationSettingsService::class);

        $this->assertTrue($service->forAccount('acc-1')['in_app']);

        $this->assertFalse($service->update($this->tenant, ['in_app' => false])['in_app']);
        $this->assertFalse($service->forAccount('acc-1')['in_app']);
        $this->assertTrue($service->forAccount('unknown')['in_app']);
    }

    public function test_another_accounts_pusher_is_refused(): void
    {
        $this->expectException(ApiRequestFailed::class);

        app(NotificationSettingsService::class)->update($this->tenant, ['pusher_ids' => ['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']]);
    }
}
