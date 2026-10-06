<?php

namespace NextDeveloper\Monitoring\Http\Controllers\Channels;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NextDeveloper\Monitoring\Http\Controllers\AbstractMonitoringController;

class ChannelsController extends AbstractMonitoringController
{
    public function index(): JsonResponse
    {
        return $this->respond(fn () => ['data' => $this->service->listChannels()]);
    }

    /** The only response that carries the signing secret (plus rotate); it is never returned again. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:200',
            'url' => 'required|url|max:2000',
            'enabled' => 'sometimes|boolean',
            'timeout_seconds' => 'sometimes|integer|min:1|max:30',
            'headers' => 'sometimes|array|max:20',
            'headers.*' => 'nullable|string|max:1000',
            'severity' => 'sometimes|array',
            'severity.*' => 'string|in:warning,critical',
            'device_types' => 'sometimes|array',
            'device_types.*' => 'string|max:50',
            'tags' => 'sometimes|array|max:20',
            'tags.*' => 'string|max:200',
        ]);

        return $this->respond(fn () => ['data' => $this->service->createChannel($data)], 201);
    }

    public function update(Request $request, string $channelId): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:200',
            'url' => 'sometimes|url|max:2000',
            'enabled' => 'sometimes|boolean',
            'timeout_seconds' => 'sometimes|integer|min:1|max:30',
            'headers' => 'sometimes|array|max:20',
            'headers.*' => 'nullable|string|max:1000',
            'severity' => 'sometimes|array',
            'severity.*' => 'string|in:warning,critical',
            'device_types' => 'sometimes|array',
            'device_types.*' => 'string|max:50',
            'tags' => 'sometimes|array|max:20',
            'tags.*' => 'string|max:200',
        ]);

        return $this->respond(fn () => ['data' => $this->service->updateChannel($channelId, $data)]);
    }

    public function destroy(string $channelId): JsonResponse
    {
        return $this->respond(function () use ($channelId) {
            $this->service->deleteChannel($channelId);

            return [];
        }, 204);
    }

    public function test(string $channelId): JsonResponse
    {
        return $this->respond(fn () => ['data' => $this->service->testChannel($channelId)]);
    }

    public function rotateSecret(string $channelId): JsonResponse
    {
        return $this->respond(fn () => ['data' => $this->service->rotateChannelSecret($channelId)]);
    }

    public function deliveries(Request $request, string $channelId): JsonResponse
    {
        $filters = $request->validate(['status' => 'sometimes|string|in:pending,delivered,failed,cancelled']);

        return $this->respond(fn () => ['data' => $this->service->channelDeliveries($channelId, $filters['status'] ?? null)]);
    }

    public function replay(string $channelId, string $deliveryId): JsonResponse
    {
        return $this->respond(function () use ($channelId, $deliveryId) {
            $this->service->replayChannelDelivery($channelId, $deliveryId);

            return ['data' => ['queued' => true]];
        }, 202);
    }
}
