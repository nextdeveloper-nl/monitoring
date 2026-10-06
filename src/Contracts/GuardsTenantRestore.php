<?php

namespace NextDeveloper\Monitoring\Contracts;

use NextDeveloper\Monitoring\Models\MonitoringTenant;

/**
 * Optional hook the host application can bind to veto the automatic restore of a deleted tenant, for example while the
 * account is suspended. Without a binding the restore is allowed.
 */
interface GuardsTenantRestore
{
    public function allowsRestore(MonitoringTenant $tenant): bool;
}
