<?php

namespace NextDeveloper\Monitoring\Http\Controllers\Mqtt;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NextDeveloper\Monitoring\Http\Controllers\AbstractMonitoringController;

/**
 * MQTT ingest: devices send data to the monitoring service's MQTT broker with an MQTT credential of the account.
 * A password is only in the response that creates or rotates a credential (shown once); it is never logged.
 */
class MqttController extends AbstractMonitoringController
{
    public function index(): JsonResponse
    {
        return $this->respond(fn () => ['data' => $this->service->listMqttCredentials()]);
    }

    public function show(string $credentialId): JsonResponse
    {
        return $this->respond(fn () => ['data' => $this->service->getMqttCredential($credentialId)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:200',
            'kind' => 'required|in:device,shared',
            'username' => 'sometimes|string|max:100',
            'password' => 'sometimes|string|min:8|max:256',
            // the device's key (for example its MAC address); needed for kind device
            'device_key' => 'required_if:kind,device|string|max:100',
            'profile' => 'sometimes|in:fixlean-esp,json',
            'allow_plain' => 'sometimes|boolean',
            'auto_register' => 'sometimes|boolean',
            'enabled' => 'sometimes|boolean',
        ]);

        return $this->respond(fn () => ['data' => $this->service->createMqttCredential($data)], 201);
    }

    public function update(Request $request, string $credentialId): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:200',
            'allow_plain' => 'sometimes|boolean',
            'auto_register' => 'sometimes|boolean',
            'enabled' => 'sometimes|boolean',
        ]);

        return $this->respond(fn () => ['data' => $this->service->updateMqttCredential($credentialId, $data)]);
    }

    public function destroy(string $credentialId): JsonResponse
    {
        return $this->respond(function () use ($credentialId) {
            $this->service->deleteMqttCredential($credentialId);

            return [];
        }, 204);
    }

    /** New password (generated when none is sent); the old one stops at once. */
    public function rotate(Request $request, string $credentialId): JsonResponse
    {
        $data = $request->validate(['password' => 'sometimes|string|min:8|max:256']);

        return $this->respond(fn () => ['data' => $this->service->rotateMqttCredential($credentialId, $data['password'] ?? null)]);
    }

    public function profiles(): JsonResponse
    {
        return $this->respond(fn () => ['data' => $this->service->mqttProfiles()]);
    }

    public function unregistered(): JsonResponse
    {
        return $this->respond(fn () => ['data' => $this->service->mqttUnregistered()]);
    }

    /** Pre-register a device key on a host. 409 when already bound. */
    public function bindHost(Request $request, string $hostId): JsonResponse
    {
        $data = $request->validate([
            'device_key' => 'required|string|max:100',
            'profile' => 'sometimes|in:fixlean-esp,json',
        ]);

        return $this->respond(fn () => ['data' => $this->service->bindHostMqtt($hostId, $data)]);
    }

    public function showHost(string $hostId): JsonResponse
    {
        return $this->respond(fn () => ['data' => $this->service->getHostMqtt($hostId)]);
    }

    /** Also removes the device's two checks. */
    public function unbindHost(string $hostId): JsonResponse
    {
        return $this->respond(function () use ($hostId) {
            $this->service->unbindHostMqtt($hostId);

            return [];
        }, 204);
    }
}
