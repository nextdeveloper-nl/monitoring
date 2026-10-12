<?php

namespace NextDeveloper\Monitoring\Contracts;

/**
 * Host application hook: finds a pusher (a delivery target the customer set up in the platform) the current user may use.
 * Monitoring notifications are triggered on the pushers an account lists in its notification settings, so a customer must
 * not be able to name another account's pusher. Bind it in the host application; without a binding pushers cannot be set.
 */
interface ResolvesPushers
{
    /** @return array{uuid: string, name: string}|null null when the pusher does not exist or is not the current user's. */
    public function find(string $pusherId): ?array;
}
