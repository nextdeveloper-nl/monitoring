<?php

namespace NextDeveloper\Monitoring\Contracts;

interface MonitoringDriver extends ManagesTenants, ManagesHosts, ReadsMetrics, ManagesAlerts, PushesData
{
    public function driverName(): string;

    /** Capability: tenants|hosts|metrics|alerts|push */
    public function supports(string $capability): bool;
}
