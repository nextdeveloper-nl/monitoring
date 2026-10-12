<?php

namespace NextDeveloper\Monitoring\Http\Controllers\Tenant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NextDeveloper\Monitoring\Http\Controllers\AbstractMonitoringController;

class TenantController extends AbstractMonitoringController
{
    public function show(): JsonResponse
    {
        return $this->respond(fn () => ['data' => $this->service->tenantInfo()]);
    }

    /** What the platform does with the account's notifications besides its channels: email on/off and the pushers it triggers. */
    public function notificationSettings(): JsonResponse
    {
        return $this->respond(fn () => ['data' => $this->service->notificationSettings()]);
    }

    /** Partial: send only what changes. `pusher_ids` replaces the list. */
    public function updateNotificationSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'sometimes|boolean',
            'in_app' => 'sometimes|boolean',
            'pusher_ids' => 'sometimes|array|max:20',
            'pusher_ids.*' => 'string|uuid',
        ]);

        return $this->respond(fn () => ['data' => $this->service->updateNotificationSettings($data)]);
    }
}
