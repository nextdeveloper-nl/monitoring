<?php

namespace NextDeveloper\Monitoring\Http\Controllers\Vms;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NextDeveloper\Monitoring\Http\Controllers\AbstractMonitoringController;

/**
 * "Enable / Disable monitoring" for a virtual machine, through the PlusClouds VM agent. Opt-in per VM: nothing is
 * monitored until it is enabled. Enabling checks that the VM belongs to the current user.
 */
class VmMonitoringController extends AbstractMonitoringController
{
    /** Whether monitoring is on for the VM: the vm.agent check and its state. */
    public function show(Request $request, string $vmId): JsonResponse
    {
        $this->validVmId($request, $vmId);

        return $this->respond(fn () => ['data' => $this->service->vmMonitoring($vmId)]);
    }

    public function enable(Request $request, string $vmId): JsonResponse
    {
        $this->validVmId($request, $vmId);

        return $this->respond(fn () => ['data' => $this->service->enableVmMonitoring($vmId)], 201);
    }

    /** Removes the vm.agent check; the VM's telemetry is dropped again. */
    public function disable(Request $request, string $vmId): JsonResponse
    {
        $this->validVmId($request, $vmId);

        return $this->respond(function () use ($vmId) {
            $this->service->disableVmMonitoring($vmId);

            return [];
        }, 204);
    }

    private function validVmId(Request $request, string $vmId): void
    {
        validator(['vm_id' => $vmId], ['vm_id' => 'required|uuid'])->validate();
    }
}
