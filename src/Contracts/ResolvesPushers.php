<?php

namespace NextDeveloper\Monitoring\Contracts;

/**
 * Host application hook: finds a pusher (a delivery target the customer set up in the platform) the current user may use.
 * Monitoring notifications are triggered on the pushers an account lists in its notification settings, so a customer must
 * not be able to name another account's pusher. Bind it in the host application; without a binding pushers cannot be set.
 */
interface ResolvesPushers
{
    /** @return array{id: int, uuid: string, name: string}|null null when the pusher does not exist or is not the current user's. `id` is the pusher's internal id. */
    public function find(string $pusherId): ?array;

    /**
     * Describes pushers by internal id, without the current user's restriction (used to show a rule's pusher and to trigger it).
     *
     * @param  int[] $ids
     * @return array<int, array{id: int, uuid: string, name: string, iam_account_id: int|null}> keyed by id
     */
    public function describe(array $ids): array;
}
