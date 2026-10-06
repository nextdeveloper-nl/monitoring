<?php

namespace NextDeveloper\Monitoring\Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use NextDeveloper\Monitoring\DataTransferObjects\AlertRoute;
use NextDeveloper\Monitoring\DataTransferObjects\Check;
use NextDeveloper\Monitoring\DataTransferObjects\Host;
use NextDeveloper\Monitoring\DataTransferObjects\Site;
use NextDeveloper\Monitoring\DataTransferObjects\Webhook;
use NextDeveloper\Monitoring\Drivers\PlusCloudsDriver;
use NextDeveloper\Monitoring\Enums\AlertSeverity;
use NextDeveloper\Monitoring\Enums\AlertStatus;
use NextDeveloper\Monitoring\Enums\CheckStatus;
use NextDeveloper\Monitoring\Enums\HostStatus;
use NextDeveloper\Monitoring\Enums\TenantStatus;
use NextDeveloper\Monitoring\Exceptions\ApiRequestFailed;
use NextDeveloper\Monitoring\Exceptions\UnsupportedOperation;
use NextDeveloper\Monitoring\Models\MonitoringServer;
use NextDeveloper\Monitoring\Tests\TestCase;

/** Http::fake tests: request shape (path, headers, body) and response mapping. No network. */
class PlusCloudsDriverTest extends TestCase
{
    private const TENANT = '7b7efcf0-06b4-43ad-91ad-2897042b6aa6';

    private PlusCloudsDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $server = MonitoringServer::create([
            'name' => 'pc', 'driver' => 'plusclouds', 'base_url' => 'https://mon.test/',
            'credentials' => ['token' => 'mon_abc_secret'], 'is_default' => true,
        ]);

        $this->driver = new PlusCloudsDriver($server);
    }

    /** Fresh fake per call: Http::fake() keeps earlier stubs, which would shadow later ones in the same test. */
    private function fake(array $stubs): void
    {
        Http::swap(new Factory());
        Http::fake($stubs);
    }

    private function sent(): Request
    {
        return Http::recorded()->last()[0];
    }

    public function test_tenant_upsert_sends_bearer_and_maps_status(): void
    {
        $this->fake(['*' => Http::response(['id' => 'x', 'name' => 'Acme', 'status' => 'active'], 201)]);

        $tenant = $this->driver->createTenant('Acme', ['external_id' => self::TENANT]);

        $this->assertSame(self::TENANT, $tenant->id);
        $this->assertSame(TenantStatus::Active, $tenant->status);
        $this->assertSame('PUT', $this->sent()->method());
        $this->assertSame('https://mon.test/v1/tenants/by-external-id/'.self::TENANT, $this->sent()->url());
        $this->assertSame('Bearer mon_abc_secret', $this->sent()->header('Authorization')[0]);
        $this->assertSame(['name' => 'Acme'], $this->sent()->data());
    }

    public function test_tenant_create_requires_external_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->driver->createTenant('Acme');
    }

    public function test_suspend_patches_status_and_get_tenant_uses_list_lookup(): void
    {
        $this->fake(['*' => Http::response(['name' => 'Acme', 'status' => 'suspended'])]);
        $this->assertSame(TenantStatus::Suspended, $this->driver->suspendTenant(self::TENANT)->status);
        $this->assertSame(['status' => 'suspended'], $this->sent()->data());

        $this->fake(['*' => Http::response(['items' => [['name' => 'Acme', 'status' => 'active']], 'next_cursor' => null])]);
        $this->driver->getTenant(self::TENANT);
        $this->assertStringContainsString('external_source=plusclouds', $this->sent()->url());
        $this->assertStringContainsString('external_id='.self::TENANT, $this->sent()->url());

        $this->fake(['*' => Http::response(['items' => [], 'next_cursor' => null])]);
        $this->expectException(ApiRequestFailed::class);
        $this->driver->getTenant(self::TENANT);
    }

    public function test_create_host_with_external_id_is_an_idempotent_put_with_tenant_header(): void
    {
        $this->fake(['*' => Http::response(['id' => 'd1', 'name' => 'vm', 'address' => '10.0.0.1', 'type' => 'vm', 'tags' => [], 'external' => ['id' => 'vm-uuid', 'type' => 'App\\Vm'], 'status' => ['availability' => 'up']])]);

        $host = $this->driver->createHost(self::TENANT, new Host(null, 'vm', '10.0.0.1', type: 'vm', externalId: 'vm-uuid', externalType: 'App\\Vm'));

        $this->assertSame('PUT', $this->sent()->method());
        $this->assertStringContainsString('/v1/devices/by-external-id/vm-uuid', $this->sent()->url());
        $this->assertStringContainsString('type=App%5CVm', $this->sent()->url());
        $this->assertSame(self::TENANT, $this->sent()->header('X-Tenant-External-ID')[0]);
        $this->assertSame(HostStatus::Up, $host->status);
        $this->assertSame('vm-uuid', $host->externalId);
        $this->assertArrayNotHasKey('external', $this->sent()->data());
    }

    public function test_create_host_without_external_id_posts_and_needs_type(): void
    {
        $this->fake(['*' => Http::response(['id' => 'd1', 'name' => 'vm'])]);

        $this->driver->createHost(self::TENANT, new Host(null, 'vm', type: 'server'));
        $this->assertSame('POST', $this->sent()->method());

        $this->expectException(InvalidArgumentException::class);
        $this->driver->createHost(self::TENANT, new Host(null, 'vm'));
    }

    public function test_host_status_mapping(): void
    {
        foreach (['up' => HostStatus::Up, 'down' => HostStatus::Down, 'disabled' => HostStatus::Disabled, 'unmonitored' => HostStatus::Unknown, 'unknown' => HostStatus::Unknown] as $availability => $expected) {
            $this->fake(['*' => Http::response(['id' => 'd', 'name' => 'n', 'status' => ['availability' => $availability]])]);

            $this->assertSame($expected, $this->driver->getHost(self::TENANT, 'd')->status, $availability);
        }
    }

    public function test_update_host_patches_only_allowed_attributes_and_delete_confirms(): void
    {
        $this->fake(['*' => Http::response(['id' => 'd', 'name' => 'new'])]);

        $this->driver->updateHost(self::TENANT, 'd', ['name' => 'new', 'status' => 'hacked', 'tags' => ['a' => null]]);
        $this->assertSame('PATCH', $this->sent()->method());
        $this->assertSame(['name' => 'new', 'tags' => ['a' => null]], $this->sent()->data());

        $this->fake(['*' => Http::response(null, 204)]);
        $this->driver->deleteHost(self::TENANT, 'd');
        $this->assertSame('DELETE', $this->sent()->method());
        $this->assertStringContainsString('confirm=true', $this->sent()->url());
    }

    public function test_list_hosts_follows_cursors(): void
    {
        $this->fake(['*' => Http::sequence()
            ->push(['items' => [['id' => '1', 'name' => 'a']], 'next_cursor' => 'c2'])
            ->push(['items' => [['id' => '2', 'name' => 'b']], 'next_cursor' => null])]);

        $this->assertCount(2, $this->driver->listHosts(self::TENANT));
        $this->assertStringContainsString('cursor=c2', $this->sent()->url());
    }

    public function test_checks_create_state_and_test_host(): void
    {
        $this->fake(['*' => Http::response(['id' => 'c1', 'device_id' => 'd1', 'name' => 'web', 'plugin' => 'http', 'interval_seconds' => 60, 'enabled' => true, 'is_host_check' => true])]);
        $check = $this->driver->createCheck(self::TENANT, new Check(null, 'd1', 'web', 'http', ['url' => 'https://x'], isHostCheck: true, raw: ['failure_count' => 1, 'junk' => 1]));

        $this->assertStringContainsString('/v1/devices/d1/checks', $this->sent()->url());
        $this->assertSame(1, $this->sent()->data()['failure_count']);
        $this->assertArrayNotHasKey('junk', $this->sent()->data());
        $this->assertTrue($check->isHostCheck);

        $this->fake(['*' => Http::response(['phase' => 'PROBLEM', 'status' => 'CRITICAL', 'last_output' => 'down', 'last_metrics' => ['total_ms' => 1.5], 'since' => '2026-10-04T12:00:00Z', 'incident_id' => 'i1'])]);
        $state = $this->driver->getCheckState(self::TENANT, 'c1');
        $this->assertSame(CheckStatus::Critical, $state->status);
        $this->assertSame('i1', $state->incidentId);

        $this->fake(['*' => Http::response(['type' => 'https://monitor.plusclouds.com/problems/no-state', 'status' => 404], 404)]);
        $this->assertNull($this->driver->getCheckState(self::TENANT, 'c1'));

        $this->fake(['*' => Http::response([['check_id' => 'c1', 'name' => 'web', 'plugin' => 'http', 'status' => 'OK', 'duration_ms' => 223.6, 'metrics' => ['total_ms' => 223.5]]])]);
        $results = $this->driver->testHost(self::TENANT, 'd1');
        $this->assertSame(224, $results->first()->durationMs);
        $this->assertSame(CheckStatus::Ok, $results->first()->status);
    }

    public function test_check_state_rethrows_other_404s(): void
    {
        $this->fake(['*' => Http::response(['type' => 'https://monitor.plusclouds.com/problems/not-found', 'status' => 404], 404)]);

        $this->expectException(ApiRequestFailed::class);
        $this->driver->getCheckState(self::TENANT, 'missing');
    }

    public function test_alerts_map_incidents_and_ack_note_becomes_comment(): void
    {
        $incident = ['id' => 'i1', 'summary' => 'down', 'severity' => 'critical', 'status' => 'acknowledged', 'device_id' => 'd1', 'opened_at' => '2026-10-04T12:00:00Z'];
        $this->fake(['*' => Http::response($incident)]);

        $alert = $this->driver->acknowledgeAlert(self::TENANT, 'i1', 'looking');

        $this->assertSame(AlertSeverity::Critical, $alert->severity);
        $this->assertSame(AlertStatus::Acknowledged, $alert->status);
        $this->assertSame('d1', $alert->hostId);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/incidents/i1/comments') && $r['body'] === 'looking');

        $this->fake(['*' => Http::response(['items' => [], 'next_cursor' => null])]);
        $this->driver->listAlerts(self::TENANT, ['host_id' => 'd1', 'status' => 'active']);
        $this->assertStringContainsString('device_id=d1', $this->sent()->url());
        $this->assertStringNotContainsString('host_id', $this->sent()->url());
    }

    public function test_sites_and_notifications_upsert_by_external_id(): void
    {
        $this->fake(['*' => Http::response(['id' => 's1', 'name' => 'DC', 'country' => 'TR'], 201)]);
        $this->driver->upsertSite(self::TENANT, new Site(null, 'DC', 'TR', externalId: 'site-uuid', externalType: 'App\\Site'));
        $this->assertStringContainsString('/v1/sites/by-external-id/site-uuid', $this->sent()->url());

        $this->fake(['*' => Http::response(['id' => 'w1', 'name' => 'leo', 'url' => 'https://leo/hook', 'secret' => 'whsec_x', 'header_names' => ['X-Env']], 201)]);
        $webhook = $this->driver->upsertWebhook(self::TENANT, new Webhook(null, 'leo', 'https://leo/hook', headers: ['X-Env' => 'test'], externalId: 'ch-uuid'));
        $this->assertSame('whsec_x', $webhook->secret);
        $this->assertArrayNotHasKey('secret', $webhook->raw);
        $this->assertArrayNotHasKey('secret', $webhook->toArray());

        $this->fake(['*' => Http::response(['id' => 'r1', 'name' => 'crit', 'endpoint_id' => 'w1', 'position' => 10])]);
        $route = $this->driver->upsertAlertRoute(self::TENANT, new AlertRoute(null, 'crit', 'w1', match: ['severity' => ['critical']], externalId: 'rt-uuid'));
        $this->assertSame('w1', $this->sent()->data()['endpoint_id']);
        $this->assertSame(10, $route->position);

        $this->expectException(InvalidArgumentException::class);
        $this->driver->upsertSite(self::TENANT, new Site(null, 'No external id'));
    }

    public function test_push_is_unsupported_and_capabilities_listed(): void
    {
        foreach (['tenants', 'hosts', 'checks', 'alerts', 'sites', 'notifications', 'metrics', 'usage', 'members', 'plugins', 'connection', 'restore'] as $capability) {
            $this->assertTrue($this->driver->supports($capability), $capability);
        }
        $this->assertFalse($this->driver->supports('push'));

        $this->expectException(UnsupportedOperation::class);
        $this->driver->pushMetrics(self::TENANT, 'd1', []);
    }

    public function test_metrics_query_uses_repeated_names_and_maps_points(): void
    {
        $this->fake(['*' => Http::response(['resolution' => 'raw', 'step' => 30, 'series' => [
            ['device_id' => 'd1', 'check_id' => 'c1', 'object' => '', 'name' => 'total_ms', 'unit' => 'ms', 'series_ids' => [5], 'points' => [['t' => '2026-10-04T12:32:00Z', 'v' => 370.9], ['t' => '2026-10-04T12:32:30Z', 'v' => 12]]],
        ]])]);

        $series = $this->driver->getMetrics(self::TENANT, 'd1', ['total_ms', 'ttfb_ms'], new \DateTimeImmutable('2026-10-04T12:00:00Z'), null, ['step' => 30, 'agg' => 'max', 'check_id' => 'c1']);

        $url = urldecode($this->sent()->url());
        $this->assertStringContainsString('/v1/metrics/query?', $url);
        $this->assertStringContainsString('name=total_ms&name=ttfb_ms', $url);
        $this->assertStringContainsString('device_id=d1', $url);
        $this->assertStringContainsString('step=30', $url);
        $this->assertStringContainsString('agg=max', $url);
        $this->assertSame(self::TENANT, $this->sent()->header('X-Tenant-External-ID')[0]);

        $this->assertCount(1, $series);
        $this->assertSame('total_ms', $series->first()->key);
        $this->assertSame('ms', $series->first()->unit);
        $this->assertSame('raw', $series->first()->raw['resolution']);
        $this->assertSame('c1', $series->first()->raw['check_id']);
        $this->assertCount(2, $series->first()->points);
        $this->assertSame(370.9, $series->first()->points->first()->value);
    }

    public function test_metric_series_needs_a_selector(): void
    {
        $this->fake(['*' => Http::response(['items' => [['name' => 'total_ms', 'unit' => 'ms']]])]);

        $this->assertCount(1, $this->driver->listMetricSeries(self::TENANT, 'd1', null, ['total_ms']));
        $this->assertStringContainsString('device_id=d1', $this->sent()->url());

        $this->expectException(InvalidArgumentException::class);
        $this->driver->listMetricSeries(self::TENANT);
    }

    public function test_usage_reads_whole_hours_without_tenant_header_and_follows_cursors(): void
    {
        $item = ['account_id' => self::TENANT, 'period_start' => '2026-10-04T12:00:00Z', 'period_end' => '2026-10-04T13:00:00Z', 'billable_check_seconds' => 21600, 'device_seconds' => 7200, 'revision' => 1, 'breakdown' => ['http' => ['count_seconds' => 7200, 'weight' => 2]]];
        $this->fake(['*' => Http::sequence()->push(['items' => [$item], 'next_cursor' => 'c2'])->push(['items' => [$item], 'next_cursor' => null])]);

        $usage = $this->driver->usage(new \DateTimeImmutable('2026-10-04T12:00:00+03:00'), new \DateTimeImmutable('2026-10-04T15:00:00Z'));

        $this->assertCount(2, $usage);
        $this->assertSame(21600, $usage->first()['billable_check_seconds']);
        $url = urldecode($this->sent()->url());
        $this->assertStringContainsString('/v1/usage/tenants?', $url);
        $this->assertStringContainsString('from=2026-10-04T09:00:00Z', $url);
        $this->assertStringContainsString('to=2026-10-04T15:00:00Z', $url);
        $this->assertStringContainsString('cursor=c2', $url);
        $this->assertFalse($this->sent()->hasHeader('X-Tenant-External-ID'));
    }

    public function test_usage_refuses_partial_hours_and_surfaces_open_hour_errors(): void
    {
        try {
            $this->driver->usage(new \DateTimeImmutable('2026-10-04T12:30:00Z'), new \DateTimeImmutable('2026-10-04T14:00:00Z'));
            $this->fail('expected exception');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $this->fake(['*' => Http::response(['type' => 'https://monitor.plusclouds.com/problems/usage-hour-open', 'status' => 422, 'detail' => 'closed up to 2026-10-04T12:00:00Z'], 422)]);

        try {
            $this->driver->usage(new \DateTimeImmutable('2026-10-04T12:00:00Z'), new \DateTimeImmutable('2026-10-04T14:00:00Z'));
            $this->fail('expected exception');
        } catch (ApiRequestFailed $e) {
            $this->assertSame(422, $e->status);
            $this->assertStringEndsWith('/usage-hour-open', $e->body['type']);
        }
    }

    public function test_webhook_deliveries_filter_by_status_and_replay_posts(): void
    {
        $this->fake(['*' => Http::response(['items' => [['id' => 'dl1', 'status' => 'failed', 'attempts' => 7, 'last_error' => 'timeout']], 'next_cursor' => null])]);

        $deliveries = $this->driver->listWebhookDeliveries(self::TENANT, 'w1', 'failed');
        $this->assertCount(1, $deliveries);
        $this->assertStringContainsString('/v1/webhooks/w1/deliveries', $this->sent()->url());
        $this->assertStringContainsString('status=failed', $this->sent()->url());

        $this->fake(['*' => Http::response(null, 202)]);
        $this->driver->replayWebhookDelivery(self::TENANT, 'w1', 'dl1');
        $this->assertSame('POST', $this->sent()->method());
        $this->assertStringEndsWith('/v1/webhooks/w1/deliveries/dl1/replay', $this->sent()->url());
    }

    public function test_webhook_exposes_disabled_reason(): void
    {
        $this->fake(['*' => Http::response(['items' => [['id' => 'w1', 'name' => 'n', 'url' => 'https://x', 'enabled' => false, 'disabled_reason' => 'receiver answered 410 Gone']], 'next_cursor' => null])]);

        $webhook = $this->driver->listWebhooks(self::TENANT)->first();

        $this->assertFalse($webhook->enabled);
        $this->assertSame('receiver answered 410 Gone', $webhook->disabledReason);
    }

    public function test_acting_as_adds_actor_header_to_tenant_calls_without_changing_the_original(): void
    {
        $this->fake(['*' => Http::response(['items' => [], 'next_cursor' => null])]);

        $this->driver->actingAs('user-uuid')->listHosts(self::TENANT);
        $this->assertSame('user-uuid', $this->sent()->header('X-Actor-External-ID')[0]);
        $this->assertSame(self::TENANT, $this->sent()->header('X-Tenant-External-ID')[0]);

        $this->driver->listHosts(self::TENANT);
        $this->assertFalse($this->sent()->hasHeader('X-Actor-External-ID'));

        $this->driver->actingAs('user-uuid')->actingAs(null)->listHosts(self::TENANT);
        $this->assertFalse($this->sent()->hasHeader('X-Actor-External-ID'));
    }

    public function test_upsert_member_looks_up_the_server_tenant_id_and_sends_role(): void
    {
        $this->fake(['*' => Http::sequence()
            ->push(['items' => [['id' => 'srv-tenant-uuid', 'name' => 'Acme', 'status' => 'active']], 'next_cursor' => null])
            ->push(['role' => 'operator'], 201)]);

        $this->driver->upsertMember(self::TENANT, 'user-uuid', 'operator');

        $this->assertSame('PUT', $this->sent()->method());
        $this->assertStringEndsWith('/v1/tenants/srv-tenant-uuid/members/by-external-id/user-uuid', $this->sent()->url());
        $this->assertSame(['role' => 'operator'], $this->sent()->data());
        $this->assertFalse($this->sent()->hasHeader('X-Tenant-External-ID'));
    }

    public function test_list_plugins_returns_manifests(): void
    {
        $this->fake(['*' => Http::response(['items' => [['type' => 'icmp', 'default_interval_seconds' => 60, 'metrics' => [['name' => 'rtt_avg_ms']]], ['type' => 'http']]])]);

        $plugins = $this->driver->listPlugins(self::TENANT);

        $this->assertSame(['icmp', 'http'], $plugins->pluck('type')->all());
        $this->assertStringEndsWith('/v1/plugins', $this->sent()->url());
        $this->assertSame(self::TENANT, $this->sent()->header('X-Tenant-External-ID')[0]);
    }

    public function test_check_connection_reads_tenants_with_the_platform_key_and_fails_on_rejection(): void
    {
        $this->fake(['*' => Http::response(['items' => [], 'next_cursor' => null])]);
        $this->driver->checkConnection();
        $this->assertStringContainsString('/v1/tenants?limit=1', $this->sent()->url());
        $this->assertSame('Bearer mon_abc_secret', $this->sent()->header('Authorization')[0]);
        $this->assertFalse($this->sent()->hasHeader('X-Tenant-External-ID'));

        $this->fake(['*' => Http::response(['type' => 'https://monitor.plusclouds.com/problems/unauthorized', 'status' => 401], 401)]);
        $this->expectException(ApiRequestFailed::class);
        $this->driver->checkConnection();
    }

    public function test_deleted_tenants_are_reported_as_deleted_and_can_be_restored(): void
    {
        $this->fake(['*' => Http::response(['items' => [['id' => 'srv-uuid', 'name' => 'Acme', 'status' => 'deleted']], 'next_cursor' => null])]);
        $this->assertSame(TenantStatus::Deleted, $this->driver->getTenant(self::TENANT)->status);

        $this->fake(['*' => Http::response(['id' => 'srv-uuid', 'name' => 'Acme', 'status' => 'active'])]);
        $tenant = $this->driver->restoreTenant(self::TENANT);

        $this->assertSame('POST', $this->sent()->method());
        $this->assertStringEndsWith('/v1/tenants/by-external-id/'.self::TENANT.'/restore', $this->sent()->url());
        $this->assertSame(TenantStatus::Active, $tenant->status);
    }

    public function test_problem_response_becomes_api_request_failed_with_body(): void
    {
        $this->fake(['*' => Http::response(['type' => 'https://monitor.plusclouds.com/problems/tenant-suspended', 'status' => 403], 403)]);

        try {
            $this->driver->listHosts(self::TENANT);
            $this->fail('expected exception');
        } catch (ApiRequestFailed $e) {
            $this->assertSame(403, $e->status);
            $this->assertStringEndsWith('/tenant-suspended', $e->body['type']);
        }
    }
}
