<?php

namespace NextDeveloper\Monitoring\Http\Controllers\Credentials;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NextDeveloper\Monitoring\Http\Controllers\AbstractMonitoringController;

/**
 * What checks log in with (SNMP community or user, HTTP auth, ...). Secrets are write-only: no endpoint ever returns
 * them, only which secret fields are set (secrets_set).
 */
class CredentialsController extends AbstractMonitoringController
{
    /** The kinds of credential and their fields; secret fields are marked writeOnly. */
    public function types(): JsonResponse
    {
        return $this->respond(fn () => ['data' => $this->service->credentialTypes()]);
    }

    public function index(): JsonResponse
    {
        return $this->respond(fn () => ['data' => $this->service->listCredentials()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:200',
            'type' => 'required|string|max:50',
            'fields' => 'required|array|max:20',
            'fields.*' => 'nullable|string|max:2000',
        ]);

        return $this->respond(fn () => ['data' => $this->service->createCredential($data)], 201);
    }

    /** Partial: send only what changes. A secret you do not send keeps its stored value. */
    public function update(Request $request, string $credentialId): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:200',
            'type' => 'sometimes|string|max:50',
            'fields' => 'sometimes|array|max:20',
            'fields.*' => 'nullable|string|max:2000',
        ]);

        return $this->respond(fn () => ['data' => $this->service->updateCredential($credentialId, $data)]);
    }

    /** Refused with 409 in-use while a check still uses it. */
    public function destroy(string $credentialId): JsonResponse
    {
        return $this->respond(function () use ($credentialId) {
            $this->service->deleteCredential($credentialId);

            return [];
        }, 204);
    }
}
