<?php

namespace NextDeveloper\Monitoring\Contracts;

use NextDeveloper\Monitoring\DataTransferObjects\Tenant;

interface ManagesTenants
{
    public function createTenant(string $name, array $options = []): Tenant;

    public function getTenant(string $tenantId): Tenant;

    public function suspendTenant(string $tenantId): Tenant;

    public function resumeTenant(string $tenantId): Tenant;

    public function deleteTenant(string $tenantId): void;
}
