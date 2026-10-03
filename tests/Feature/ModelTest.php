<?php

namespace NextDeveloper\Monitoring\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use NextDeveloper\Monitoring\Models\MonitoringServer;
use NextDeveloper\Monitoring\Tests\TestCase;

class ModelTest extends TestCase
{
    public function test_credentials_are_encrypted_at_rest(): void
    {
        $server = MonitoringServer::create([
            'name' => 'pc', 'driver' => 'null', 'base_url' => 'https://x.test',
            'credentials' => ['token' => 'secret-token'],
        ]);

        $raw = DB::table('monitoring_servers')->where('id', $server->id)->value('credentials');

        $this->assertStringNotContainsString('secret-token', $raw);
        $this->assertSame('secret-token', $server->fresh()->credential('token'));
        $this->assertArrayNotHasKey('credentials', $server->toArray());
    }

    public function test_only_server_and_tenant_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('monitoring_servers'));
        $this->assertTrue(Schema::hasTable('monitoring_tenants'));
        foreach (['monitoring_hosts', 'monitoring_metrics', 'monitoring_alerts'] as $t) {
            $this->assertFalse(Schema::hasTable($t));
        }
    }
}
