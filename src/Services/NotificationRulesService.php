<?php

namespace NextDeveloper\Monitoring\Services;

use Illuminate\Support\Collection;
use NextDeveloper\IAM\Helpers\UserHelper;
use NextDeveloper\Monitoring\Contracts\ResolvesPushers;
use NextDeveloper\Monitoring\Exceptions\ApiRequestFailed;
use NextDeveloper\Monitoring\Models\MonitoringTenant;
use NextDeveloper\Monitoring\Models\NotificationRule;

/**
 * An account's notification rules: "when a monitoring notification like this arrives, trigger this pusher". The customer
 * manages them through /monitoring/notification-rules; the host application's reaction asks matching() which pushers to
 * trigger for a notification. Every query is limited to the current account.
 */
class NotificationRulesService
{
    public const EVENT_TYPES = [
        'monitoring.incident.opened',
        'monitoring.incident.updated',
        'monitoring.incident.acknowledged',
        'monitoring.incident.resolved',
        'monitoring.incident.commented',
        'monitoring.tenant.device_limit_reached',
        'monitoring.tenant.check_limit_reached',
    ];

    public const SEVERITIES = ['warning', 'critical'];

    /** whoopsy: a Whoopsy! band alert. threshold: a threshold rule of the check. check_failure: the check itself failed. */
    public const ALERT_KINDS = ['whoopsy', 'threshold', 'check_failure'];

    private const MAX_RULES = 50;

    /** @return array<int, array> */
    public function list(): array
    {
        $rules = NotificationRule::query()->where('iam_account_id', $this->accountId())->orderBy('id')->get();
        $pushers = $this->pushers($rules);

        return $rules->map(fn (NotificationRule $r) => $this->shape($r, $pushers))->values()->all();
    }

    public function get(string $ruleId): array
    {
        $rule = $this->find($ruleId);

        return $this->shape($rule, $this->pushers(collect([$rule])));
    }

    /** $data: name, pusher_id (uuid of the user's pusher), enabled?, event_types?, severities?, alert_kinds?, host_ids?. */
    public function create(array $data): array
    {
        if (NotificationRule::query()->where('iam_account_id', $this->accountId())->count() >= self::MAX_RULES) {
            throw $this->problem(422, 'Too many rules: at most '.self::MAX_RULES.' per account.');
        }

        $rule = NotificationRule::create([
            'iam_account_id' => $this->accountId(),
            'iam_user_id' => UserHelper::me()?->id,
            'name' => $data['name'],
            'enabled' => $data['enabled'] ?? true,
            'event_types' => $data['event_types'] ?? [],
            'severities' => $data['severities'] ?? [],
            'alert_kinds' => $data['alert_kinds'] ?? [],
            'host_ids' => $data['host_ids'] ?? [],
            'common_pusher_id' => $this->pusherId($data['pusher_id']),
        ]);

        return $this->get($rule->uuid ?? $rule->fresh()->uuid);
    }

    /** Partial: only the keys sent change; a list sent replaces the whole list. */
    public function update(string $ruleId, array $data): array
    {
        $rule = $this->find($ruleId);

        $changes = array_intersect_key($data, array_flip(['name', 'enabled', 'event_types', 'severities', 'alert_kinds', 'host_ids']));

        if (array_key_exists('pusher_id', $data)) {
            $changes['common_pusher_id'] = $this->pusherId($data['pusher_id']);
        }

        $rule->update($changes);

        return $this->get($ruleId);
    }

    public function delete(string $ruleId): void
    {
        $this->find($ruleId)->delete();
    }

    /**
     * The enabled rules of an account (its monitoring tenant's external id) that a notification matches, each with the
     * pusher it triggers: [[rule, pusher], ...]. For the host application's reaction; not limited to the current user.
     *
     * @param  array $payload the notification as the monitoring service sent it (a CloudEvents object)
     * @return Collection<int, array{0: NotificationRule, 1: array}>
     */
    public function matching(string $accountId, array $payload): Collection
    {
        $tenant = MonitoringTenant::query()->where('external_tenant_id', $accountId)->first();

        if (! $tenant) {
            return collect();
        }

        $rules = NotificationRule::query()->where('iam_account_id', $tenant->iam_account_id)->where('enabled', true)->get()
            ->filter(fn (NotificationRule $r) => self::matches($r, $payload))->values();

        $pushers = $this->pushers($rules);

        return $rules->map(fn (NotificationRule $r) => [$r, $pushers[$r->common_pusher_id] ?? null])
            ->filter(fn (array $pair) => $pair[1] !== null && (int) $pair[1]['iam_account_id'] === (int) $tenant->iam_account_id)->values();
    }

    /** Remember that a rule fired. */
    public function recordTrigger(NotificationRule $rule): void
    {
        $rule->update(['last_triggered_at' => now(), 'trigger_count' => $rule->trigger_count + 1]);
    }

    /** Whether a rule's filters accept the notification. An empty filter accepts anything; a filter that needs data the notification lacks (a limit event has no severity) rejects it. */
    public static function matches(NotificationRule $rule, array $payload): bool
    {
        $type = (string) ($payload['type'] ?? '');
        $severity = data_get($payload, 'data.object.severity');
        $host = data_get($payload, 'data.device.id');

        return (! $rule->event_types || in_array($type, $rule->event_types, true))
            && (! $rule->severities || ($severity !== null && in_array($severity, $rule->severities, true)))
            && (! $rule->alert_kinds || (self::alertKind($payload) !== null && in_array(self::alertKind($payload), $rule->alert_kinds, true)))
            && (! $rule->host_ids || ($host !== null && in_array($host, $rule->host_ids, true)));
    }

    /** What raised an incident: whoopsy, threshold or check_failure; null for an event that is not about an incident. */
    public static function alertKind(array $payload): ?string
    {
        if (! str_starts_with((string) ($payload['type'] ?? ''), 'monitoring.incident.')) {
            return null;
        }

        $ruleId = data_get($payload, 'data.object.rule_id');

        return $ruleId === 'whoopsy' ? 'whoopsy' : ($ruleId ? 'threshold' : 'check_failure');
    }

    private function find(string $ruleId): NotificationRule
    {
        return NotificationRule::query()->where('iam_account_id', $this->accountId())->where('uuid', $ruleId)->first()
            ?? throw $this->problem(404, 'Rule not found.');
    }

    private function accountId(): int
    {
        return UserHelper::currentAccount()->id;
    }

    /** The internal id of one of the user's pushers, or a 422. */
    private function pusherId(string $uuid): int
    {
        $pusher = $this->resolver()->find($uuid) ?? throw $this->problem(422, "Pusher {$uuid} is not yours.");

        return $pusher['id'];
    }

    /** @return array<int, array> pushers of the rules, keyed by internal id */
    private function pushers(Collection $rules): array
    {
        return $this->resolver()->describe($rules->pluck('common_pusher_id')->unique()->values()->all());
    }

    private function resolver(): ResolvesPushers
    {
        return app()->bound(ResolvesPushers::class)
            ? app(ResolvesPushers::class)
            : throw $this->problem(422, 'Pushers are not available.');
    }

    private function shape(NotificationRule $r, array $pushers): array
    {
        $pusher = $pushers[$r->common_pusher_id] ?? null;

        return [
            'rule_id' => $r->uuid,
            'name' => $r->name,
            'enabled' => $r->enabled,
            'event_types' => $r->event_types,
            'severities' => $r->severities,
            'alert_kinds' => $r->alert_kinds,
            'host_ids' => $r->host_ids,
            // null when the pusher was deleted since: the rule then does nothing
            'pusher' => $pusher ? ['id' => $pusher['uuid'], 'name' => $pusher['name']] : null,
            'last_triggered_at' => $r->last_triggered_at?->toAtomString(),
            'trigger_count' => $r->trigger_count,
            'created_at' => $r->created_at?->toAtomString(),
        ];
    }

    private function problem(int $status, string $detail): ApiRequestFailed
    {
        return new ApiRequestFailed($detail, $status, ['type' => $status === 404 ? 'not-found' : 'invalid-request', 'detail' => $detail]);
    }
}
