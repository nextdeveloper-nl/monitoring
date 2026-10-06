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
            'site_ids' => 'sometimes|array|max:50',
            'site_ids.*' => 'string|max:100',
            'check_ids' => 'sometimes|array|max:100',
            'check_ids.*' => 'string|max:100',
            'event_types' => 'sometimes|array',
            'event_types.*' => 'string|in:monitoring.incident.opened,monitoring.incident.updated,monitoring.incident.acknowledged,monitoring.incident.resolved,monitoring.incident.commented',
            // grouping: one event for incidents sharing these fields, sent after group_wait_seconds
            'group_by' => 'sometimes|array|max:7',
            'group_by.*' => 'string|in:root_device_id,device_id,site_id,severity,check_id,plugin,device_type',
            'group_wait_seconds' => 'sometimes|integer|min:0|max:600',
            // resend open, unacknowledged incidents every this many seconds; null turns it off
            'repeat_interval_seconds' => 'sometimes|nullable|integer|min:300|max:604800',
            // escalation: [{after_seconds, labels?, only_if_unacknowledged?, schedule?}]
            'steps' => 'sometimes|array|max:10',
            'steps.*.after_seconds' => 'required|integer|min:0|max:604800',
            'steps.*.labels' => 'sometimes|array|max:20',
            'steps.*.only_if_unacknowledged' => 'sometimes|boolean',
            'steps.*.schedule' => 'sometimes|array',
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
            'site_ids' => 'sometimes|array|max:50',
            'site_ids.*' => 'string|max:100',
            'check_ids' => 'sometimes|array|max:100',
            'check_ids.*' => 'string|max:100',
            'event_types' => 'sometimes|array',
            'event_types.*' => 'string|in:monitoring.incident.opened,monitoring.incident.updated,monitoring.incident.acknowledged,monitoring.incident.resolved,monitoring.incident.commented',
            // grouping: one event for incidents sharing these fields, sent after group_wait_seconds
            'group_by' => 'sometimes|array|max:7',
            'group_by.*' => 'string|in:root_device_id,device_id,site_id,severity,check_id,plugin,device_type',
            'group_wait_seconds' => 'sometimes|integer|min:0|max:600',
            // resend open, unacknowledged incidents every this many seconds; null turns it off
            'repeat_interval_seconds' => 'sometimes|nullable|integer|min:300|max:604800',
            // escalation: [{after_seconds, labels?, only_if_unacknowledged?, schedule?}]
            'steps' => 'sometimes|array|max:10',
            'steps.*.after_seconds' => 'required|integer|min:0|max:604800',
            'steps.*.labels' => 'sometimes|array|max:20',
            'steps.*.only_if_unacknowledged' => 'sometimes|boolean',
            'steps.*.schedule' => 'sometimes|array',
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

    /** Which of the account's channels a sample incident would notify, in evaluation order. Sends nothing. */
    public function preview(Request $request): JsonResponse
    {
        $sample = $request->validate([
            'event_type' => 'sometimes|string|in:monitoring.incident.opened,monitoring.incident.updated,monitoring.incident.acknowledged,monitoring.incident.resolved,monitoring.incident.commented',
            'severity' => 'sometimes|string|in:warning,critical',
            'host_id' => 'sometimes|string|max:100',
            'check_id' => 'sometimes|string|max:100',
        ]);

        return $this->respond(fn () => ['data' => $this->service->previewChannels($sample)]);
    }

    /** Send a channel's failed (or cancelled) deliveries again, in bulk, between `from` and `to`. */
    public function replayAll(Request $request, string $channelId): JsonResponse
    {
        $params = $request->validate([
            'status' => 'sometimes|string|in:failed,cancelled',
            'from' => 'required|date',
            'to' => 'required|date|after:from',
        ]);

        return $this->respond(function () use ($channelId, $params) {
            $this->service->replayChannelDeliveries($channelId, $params['from'], $params['to'], $params['status'] ?? 'failed');

            return ['data' => ['queued' => true]];
        }, 202);
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
