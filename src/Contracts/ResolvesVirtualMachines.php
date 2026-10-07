<?php

namespace NextDeveloper\Monitoring\Contracts;

/**
 * Host application hook: finds a virtual machine the current user is allowed to see. The monitoring service cannot check
 * who owns a VM, and the first tenant to create a vm.agent check for a VM UUID receives that VM's metrics, so enabling
 * VM monitoring must go through this ownership check. Bind it in the host application; without a binding VM monitoring
 * is unavailable.
 */
interface ResolvesVirtualMachines
{
    /** @return array{uuid: string, name: string}|null null when the VM does not exist or is not the current user's. */
    public function find(string $vmId): ?array;
}
