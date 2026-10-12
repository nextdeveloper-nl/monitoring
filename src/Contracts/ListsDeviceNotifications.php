<?php

namespace NextDeveloper\Monitoring\Contracts;

/**
 * Optional capability ('device_notifications'): which notification routes apply to a device and what was delivered for it.
 * Items are the service's own arrays.
 */
interface ListsDeviceNotifications
{
    /**
     * Routes that apply to the device (first page only) and its recent deliveries.
     * Filters: status (pending|delivered|failed|cancelled), event_type, limit (1-100), cursor.
     *
     * @return array{routes: array, items: array, next_cursor: ?string}
     */
    public function deviceNotifications(string $tenantId, string $deviceId, array $filters = []): array;

    /** The events the service can send, with scope, trigger and an example payload, and the fields routes can match on. Static. */
    public function notificationCatalog(string $tenantId): array;
}
