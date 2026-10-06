<?php

namespace NextDeveloper\Monitoring\Http\Controllers\Checks;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NextDeveloper\Monitoring\Http\Controllers\AbstractMonitoringController;

class ChecksController extends AbstractMonitoringController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['plugin' => 'sometimes|string', 'enabled' => 'sometimes|boolean']);

        return $this->respond(fn () => ['data' => $this->service->listChecks($request->query('host_id'), $filters)]);
    }

    public function forHost(Request $request, string $hostId): JsonResponse
    {
        $filters = $request->validate(['plugin' => 'sometimes|string', 'enabled' => 'sometimes|boolean']);

        return $this->respond(fn () => ['data' => $this->service->listChecks($hostId, $filters)]);
    }

    public function show(string $checkId): JsonResponse
    {
        return $this->respond(fn () => ['data' => $this->service->getCheck($checkId)]);
    }

    public function storeForHost(Request $request, string $hostId): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:200',
            'plugin' => 'required|string',
            'config' => 'sometimes|array',
            'interval_seconds' => 'sometimes|integer|min:1|max:86400',
            'timeout_seconds' => 'sometimes|integer|min:1|max:300',
            'enabled' => 'sometimes|boolean',
            'thresholds' => 'sometimes|array|max:100',
            'failure_count' => 'sometimes|integer|min:1|max:100',
            'recovery_count' => 'sometimes|integer|min:1|max:100',
            'is_host_check' => 'sometimes|boolean',
            'unknown_is_critical' => 'sometimes|boolean',
            'runbook_url' => 'sometimes|url|max:2000',
        ]);

        return $this->respond(fn () => ['data' => $this->service->createCheck($hostId, $data)], 201);
    }

    public function update(Request $request, string $checkId): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:200',
            'config' => 'sometimes|array',
            'interval_seconds' => 'sometimes|integer|min:1|max:86400',
            'timeout_seconds' => 'sometimes|nullable|integer|min:1|max:300',
            'enabled' => 'sometimes|boolean',
            'thresholds' => 'sometimes|array|max:100',
            'failure_count' => 'sometimes|integer|min:1|max:100',
            'recovery_count' => 'sometimes|integer|min:1|max:100',
            'is_host_check' => 'sometimes|boolean',
            'unknown_is_critical' => 'sometimes|boolean',
            'runbook_url' => 'sometimes|nullable|url|max:2000',
        ]);

        return $this->respond(fn () => ['data' => $this->service->updateCheck($checkId, $data)]);
    }

    public function destroy(string $checkId): JsonResponse
    {
        return $this->respond(function () use ($checkId) {
            $this->service->deleteCheck($checkId);

            return [];
        }, 204);
    }

    public function state(string $checkId): JsonResponse
    {
        return $this->respond(fn () => ['data' => $this->service->checkState($checkId)]);
    }

    public function run(string $checkId): JsonResponse
    {
        return $this->respond(function () use ($checkId) {
            $this->service->runCheck($checkId);

            return ['data' => ['queued' => true]];
        }, 202);
    }
}
