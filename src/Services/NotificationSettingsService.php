<?php

namespace NextDeveloper\Monitoring\Services;

use NextDeveloper\Monitoring\Contracts\ResolvesPushers;
use NextDeveloper\Monitoring\Exceptions\ApiRequestFailed;
use NextDeveloper\Monitoring\Models\MonitoringTenant;

/**
 * What the platform does with an account's monitoring notifications besides the channels the customer made: send emails
 * to the owner and monitoring managers, and trigger the customer's pushers. Kept per account in the tenant row's meta
 * column (`meta.notifications`), so every account has its own settings and no table is needed.
 */
class NotificationSettingsService
{
    /** Defaults for an account that never saved settings: nothing is sent by email and no pusher is triggered. */
    private const DEFAULTS = ['email' => false, 'pusher_ids' => []];

    /** The stored settings of an account (by its monitoring tenant's external id). Used by the host application's reactions. */
    public function forAccount(string $accountId): array
    {
        $tenant = MonitoringTenant::query()->where('external_tenant_id', $accountId)->first();

        return $tenant ? $this->read($tenant) : self::DEFAULTS;
    }

    /** The settings with the pushers resolved to {id, name}. A pusher that was deleted since is dropped from the view. */
    public function show(MonitoringTenant $tenant): array
    {
        $settings = $this->read($tenant);

        return [
            'email' => $settings['email'],
            'pushers' => collect($settings['pusher_ids'])
                ->map(fn (string $id) => $this->resolver()?->find($id))
                ->filter()->map(fn (array $p) => ['id' => $p['uuid'], 'name' => $p['name']])->values()->all(),
        ];
    }

    /**
     * $data: email (bool), pusher_ids (array of pusher uuids that belong to the current user; replaces the list).
     * Only the keys sent change. Throws a 422 for a pusher that is not the user's.
     */
    public function update(MonitoringTenant $tenant, array $data): array
    {
        $settings = $this->read($tenant);

        if (array_key_exists('email', $data)) {
            $settings['email'] = (bool) $data['email'];
        }

        if (array_key_exists('pusher_ids', $data)) {
            $resolver = $this->resolver() ?? throw new ApiRequestFailed('Pushers are not available.', 422, ['type' => 'invalid-request', 'detail' => 'Pushers are not available']);
            $ids = [];

            foreach (array_unique($data['pusher_ids']) as $id) {
                $pusher = $resolver->find($id) ?? throw new ApiRequestFailed('Pusher not found.', 422, ['type' => 'invalid-request', 'detail' => "Pusher {$id} is not yours"]);
                $ids[] = $pusher['uuid'];
            }

            $settings['pusher_ids'] = $ids;
        }

        $meta = $tenant->meta ?? [];
        $meta['notifications'] = $settings;
        $tenant->update(['meta' => $meta]);

        return $this->show($tenant);
    }

    private function read(MonitoringTenant $tenant): array
    {
        $stored = $tenant->meta['notifications'] ?? [];

        return [
            'email' => (bool) ($stored['email'] ?? self::DEFAULTS['email']),
            'pusher_ids' => array_values((array) ($stored['pusher_ids'] ?? self::DEFAULTS['pusher_ids'])),
        ];
    }

    private function resolver(): ?ResolvesPushers
    {
        return app()->bound(ResolvesPushers::class) ? app(ResolvesPushers::class) : null;
    }
}
