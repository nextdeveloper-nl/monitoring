<?php

namespace NextDeveloper\Monitoring\Contracts;

use DateTimeInterface;
use Illuminate\Support\Collection;
use NextDeveloper\Monitoring\DataTransferObjects\AlertRoute;
use NextDeveloper\Monitoring\DataTransferObjects\Webhook;

/** Optional capability ('notifications'): webhook endpoints and the routes that send incidents to them. */
interface ManagesNotifications
{
    /** @return Collection<int, Webhook> */
    public function listWebhooks(string $tenantId): Collection;

    /** Idempotent by $webhook->externalId. The returned Webhook carries ->secret only when it was created; store it then. */
    public function upsertWebhook(string $tenantId, Webhook $webhook): Webhook;

    public function deleteWebhook(string $tenantId, string $webhookId): void;

    /** Sends a test event. @return array{ok: bool, status_code: ?int, response: ?string, error: ?string} */
    public function testWebhook(string $tenantId, string $webhookId): array;

    /** Returns the new secret (shown once); the old one stays valid for 24 h. */
    public function rotateWebhookSecret(string $tenantId, string $webhookId): string;

    /**
     * Delivery attempts of one webhook, newest first. $status: pending|delivered|failed|cancelled.
     *
     * @return Collection<int, array> id, event_id, event_type, subject, status, attempts, last_status_code, last_error, delivered_at, created_at
     */
    public function listWebhookDeliveries(string $tenantId, string $webhookId, ?string $status = null): Collection;

    /** Re-queue one delivery with a fresh retry window. */
    public function replayWebhookDelivery(string $tenantId, string $webhookId, string $deliveryId): void;

    /** Send an endpoint's failed (or cancelled) deliveries again, in bulk, within a time range. Returns nothing; the server queues them. */
    public function replayWebhookDeliveries(string $tenantId, string $webhookId, DateTimeInterface $from, DateTimeInterface $to, string $status = 'failed'): void;

    /**
     * Which routes a sample incident would reach. @param array{event_type?: string, severity?: string, device_id?: string, check_id?: string} $sample
     *
     * @return Collection<int, array> id, name, endpoint_id, matched, notifies, in route order
     */
    public function previewAlertRoutes(string $tenantId, array $sample): Collection;

    /** @return Collection<int, AlertRoute> */
    public function listAlertRoutes(string $tenantId): Collection;

    /** Idempotent by $route->externalId. */
    public function upsertAlertRoute(string $tenantId, AlertRoute $route): AlertRoute;

    public function deleteAlertRoute(string $tenantId, string $routeId): void;
}
