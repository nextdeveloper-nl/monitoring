<?php

namespace NextDeveloper\Monitoring\Tests\Feature;

use NextDeveloper\Monitoring\Models\NotificationRule;
use NextDeveloper\Monitoring\Services\NotificationRulesService;
use PHPUnit\Framework\TestCase;

/** The filters of a notification rule against the notifications the monitoring service sends. No database needed. */
class NotificationRulesMatchingTest extends TestCase
{
    private function rule(array $filters): NotificationRule
    {
        return new NotificationRule($filters + ['event_types' => [], 'severities' => [], 'alert_kinds' => [], 'host_ids' => []]);
    }

    private function incident(string $type = 'monitoring.incident.opened', ?string $ruleId = 'whoopsy', string $severity = 'critical', string $host = 'h1'): array
    {
        return ['type' => $type, 'data' => ['object' => ['rule_id' => $ruleId, 'severity' => $severity], 'device' => ['id' => $host]]];
    }

    public function test_a_rule_without_filters_matches_everything(): void
    {
        $this->assertTrue(NotificationRulesService::matches($this->rule([]), $this->incident()));
        $this->assertTrue(NotificationRulesService::matches($this->rule([]), ['type' => 'monitoring.tenant.device_limit_reached', 'data' => []]));
    }

    public function test_whoopsy_rule_matches_only_whoopsy_alerts(): void
    {
        $rule = $this->rule(['alert_kinds' => ['whoopsy']]);

        $this->assertTrue(NotificationRulesService::matches($rule, $this->incident(ruleId: 'whoopsy')));
        $this->assertFalse(NotificationRulesService::matches($rule, $this->incident(ruleId: 'cpu-critical')));
        $this->assertFalse(NotificationRulesService::matches($rule, $this->incident(ruleId: null)));
        $this->assertFalse(NotificationRulesService::matches($rule, ['type' => 'monitoring.tenant.check_limit_reached', 'data' => []]));
    }

    public function test_alert_kind_is_derived_from_the_rule_id(): void
    {
        $this->assertSame('whoopsy', NotificationRulesService::alertKind($this->incident(ruleId: 'whoopsy')));
        $this->assertSame('threshold', NotificationRulesService::alertKind($this->incident(ruleId: 'cpu-critical')));
        $this->assertSame('check_failure', NotificationRulesService::alertKind($this->incident(ruleId: null)));
        $this->assertNull(NotificationRulesService::alertKind(['type' => 'monitoring.tenant.device_limit_reached']));
    }

    public function test_filters_combine_with_and(): void
    {
        $rule = $this->rule(['event_types' => ['monitoring.incident.opened'], 'severities' => ['critical'], 'alert_kinds' => ['whoopsy'], 'host_ids' => ['h1']]);

        $this->assertTrue(NotificationRulesService::matches($rule, $this->incident()));
        $this->assertFalse(NotificationRulesService::matches($rule, $this->incident(type: 'monitoring.incident.resolved')));
        $this->assertFalse(NotificationRulesService::matches($rule, $this->incident(severity: 'warning')));
        $this->assertFalse(NotificationRulesService::matches($rule, $this->incident(host: 'h2')));
    }
}
