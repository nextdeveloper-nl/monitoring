<?php

namespace NextDeveloper\Monitoring\Contracts;

use Illuminate\Support\Collection;
use NextDeveloper\Monitoring\DataTransferObjects\Host;

interface ManagesHosts
{
    /** @return Collection<int, Host> */
    public function listHosts(string $tenantId, array $filters = []): Collection;

    public function getHost(string $tenantId, string $hostId): Host;

    public function createHost(string $tenantId, Host $host): Host;

    public function updateHost(string $tenantId, string $hostId, array $attributes): Host;

    public function deleteHost(string $tenantId, string $hostId): void;
}
