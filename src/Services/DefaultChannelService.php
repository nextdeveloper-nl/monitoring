<?php

namespace NextDeveloper\Monitoring\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use NextDeveloper\Monitoring\Contracts\ManagesNotifications;
use NextDeveloper\Monitoring\Contracts\MonitoringDriver;
use NextDeveloper\Monitoring\DataTransferObjects\AlertRoute;
use NextDeveloper\Monitoring\DataTransferObjects\Webhook;
use NextDeveloper\Monitoring\Models\MonitoringTenant;
use Throwable;

/**
 * The platform's own notification channel in every account's monitoring tenant: a webhook plus a catch-all alert route
 * that deliver every incident and tenant event to the host application's receiver, which verifies the signature with the
 * secret kept here and acts on the notification. Customers never see it: it is not a customer channel (its external
 * type differs from the customers' channels), so it is absent from /channels and from a host's notification view.
 *
 * The signing secret is shown once by the monitoring service, so it is kept encrypted in the tenant's meta column.
 */
class DefaultChannelService
{
    public const WEBHOOK_TYPE = 'PlusClouds\\Monitoring\\DefaultChannel';

    public const ROUTE_TYPE = 'PlusClouds\\Monitoring\\DefaultChannelRoute';

    /** Whether the feature is configured (a receiver URL is set). */
    public function enabled(): bool
    {
        return (bool) config('monitoring.default_channel.url');
    }

    /**
     * Creates the channel when the tenant has none yet. Safe to repeat: the webhook and route are upserted by a fixed
     * external id, and a secret is only requested when the tenant row has none. Never throws: a failure is logged and
     * retried at the next call.
     */
    public function ensure(MonitoringTenant $tenant, MonitoringDriver $driver): void
    {
        if (! $this->enabled() || ! $driver instanceof ManagesNotifications) {
            return;
        }

        if (! empty($tenant->meta['default_channel']['secret'])) {
            return;
        }

        try {
            $id = $tenant->external_tenant_id;
            $url = str_replace('{account_id}', $id, (string) config('monitoring.default_channel.url'));

            $webhook = $driver->upsertWebhook($id, new Webhook(
                null, 'PlusClouds notifications', $url, true, null, [], $id, self::WEBHOOK_TYPE,
            ));

            // The secret comes only with the response that created the webhook. When it already existed, make a new one.
            $secret = $webhook->secret ?? $driver->rotateWebhookSecret($id, $webhook->id);

            $route = $driver->upsertAlertRoute($id, new AlertRoute(
                // First in order and `continue`, so it sees every event and the customers' own routes still run after it
                null, 'PlusClouds notifications', $webhook->id, 1, true,
                ['event_types' => self::EVENT_TYPES], continue: true, externalId: $id, externalType: self::ROUTE_TYPE,
            ));

            $meta = $tenant->meta ?? [];
            $meta['default_channel'] = [
                'webhook_id' => $webhook->id,
                'route_id' => $route->id,
                'secret' => Crypt::encryptString($secret),
                'created_at' => now()->toAtomString(),
            ];
            $tenant->update(['meta' => $meta]);
        } catch (Throwable $e) {
            Log::warning("[DefaultChannelService] could not set up the default channel of tenant {$tenant->external_tenant_id}: ".$e->getMessage());
        }
    }

    /** Forget the stored channel, for example after the tenant was emptied: the next ensure() creates it again. */
    public function forget(MonitoringTenant $tenant): void
    {
        $meta = $tenant->meta ?? [];

        if (isset($meta['default_channel'])) {
            unset($meta['default_channel']);
            $tenant->update(['meta' => $meta]);
        }
    }

    /** The signing secret of the account's default channel, or null when it has none. */
    public function secretFor(string $accountId): ?string
    {
        $tenant = MonitoringTenant::query()->where('external_tenant_id', $accountId)->first();
        $secret = $tenant?->meta['default_channel']['secret'] ?? null;

        try {
            return $secret ? Crypt::decryptString($secret) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Every event a route can select (the service rejects others: renotify and escalated follow the route's own
     * repeat and escalation settings, and the test event is only sent on request).
     */
    public const EVENT_TYPES = [
        'monitoring.incident.opened',
        'monitoring.incident.updated',
        'monitoring.incident.acknowledged',
        'monitoring.incident.resolved',
        'monitoring.incident.commented',
        'monitoring.tenant.device_limit_reached',
        'monitoring.tenant.check_limit_reached',
    ];
}
