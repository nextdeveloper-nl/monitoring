<?php

namespace NextDeveloper\Monitoring\Contracts;

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

    /** @return Collection<int, AlertRoute> */
    public function listAlertRoutes(string $tenantId): Collection;

    /** Idempotent by $route->externalId. */
    public function upsertAlertRoute(string $tenantId, AlertRoute $route): AlertRoute;

    public function deleteAlertRoute(string $tenantId, string $routeId): void;
}
