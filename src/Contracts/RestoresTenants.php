<?php

namespace NextDeveloper\Monitoring\Contracts;

use NextDeveloper\Monitoring\DataTransferObjects\Tenant;

/** Optional capability ('restore'): bring back a tenant that was deleted, before it is purged. */
interface RestoresTenants
{
    /** The tenant becomes active again with its devices, checks and history. Fails (404) once it was purged. */
    public function restoreTenant(string $tenantId): Tenant;
}
