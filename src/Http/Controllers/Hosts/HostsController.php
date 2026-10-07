<?php

namespace NextDeveloper\Monitoring\Http\Controllers\Hosts;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NextDeveloper\Monitoring\Http\Controllers\AbstractMonitoringController;

class HostsController extends AbstractMonitoringController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => 'sometimes|string',
            'site_id' => 'sometimes|string',
            'parent_id' => 'sometimes|string',
            'availability' => 'sometimes|string|in:up,down,unusual,unknown,unmonitored,disabled',
        ]);

        return $this->respond(fn () => ['data' => $this->service->listHosts($filters)]);
    }

    public function show(string $hostId): JsonResponse
    {
        return $this->respond(fn () => ['data' => $this->service->getHost($hostId)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:200',
            'type' => 'required|string',
            'address' => 'sometimes|nullable|string|max:2000',
            'tags' => 'sometimes|array|max:50',
            'tags.*' => 'string|max:200',
            'notes' => 'sometimes|nullable|string|max:10000',
            'site_id' => 'sometimes|nullable|string',
            'parent_id' => 'sometimes|nullable|string',
            'external_id' => 'sometimes|nullable|string|max:200',
            'external_type' => 'sometimes|nullable|string|max:200',
        ]);

        return $this->respond(fn () => ['data' => $this->service->createHost($data)], 201);
    }

    public function update(Request $request, string $hostId): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:200',
            'type' => 'sometimes|string',
            'address' => 'sometimes|nullable|string|max:2000',
            'tags' => 'sometimes|array|max:50',
            'tags.*' => 'nullable|string|max:200',
            'notes' => 'sometimes|nullable|string|max:10000',
            'site_id' => 'sometimes|nullable|string',
            'parent_id' => 'sometimes|nullable|string',
        ]);

        return $this->respond(fn () => ['data' => $this->service->updateHost($hostId, $data)]);
    }

    public function destroy(string $hostId): JsonResponse
    {
        return $this->respond(function () use ($hostId) {
            $this->service->deleteHost($hostId);

            return [];
        }, 204);
    }

    public function test(string $hostId): JsonResponse
    {
        return $this->respond(fn () => ['data' => $this->service->testHost($hostId)]);
    }
}
