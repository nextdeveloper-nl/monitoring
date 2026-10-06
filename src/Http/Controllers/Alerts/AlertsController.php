<?php

namespace NextDeveloper\Monitoring\Http\Controllers\Alerts;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NextDeveloper\Monitoring\Http\Controllers\AbstractMonitoringController;

class AlertsController extends AbstractMonitoringController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => 'sometimes|string|in:open,acknowledged,resolved,active',
            'severity' => 'sometimes|string|in:warning,critical',
            'host_id' => 'sometimes|string',
            'check_id' => 'sometimes|string',
        ]);

        return $this->respond(fn () => ['data' => $this->service->listAlerts($filters)]);
    }

    public function acknowledge(Request $request, string $alertId): JsonResponse
    {
        $data = $request->validate(['note' => 'sometimes|nullable|string|max:5000']);

        return $this->respond(fn () => ['data' => $this->service->acknowledgeAlert($alertId, $data['note'] ?? null)]);
    }

    public function resolve(string $alertId): JsonResponse
    {
        return $this->respond(fn () => ['data' => $this->service->resolveAlert($alertId)]);
    }
}
