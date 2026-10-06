<?php

namespace NextDeveloper\Monitoring\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use NextDeveloper\IAM\Helpers\UserHelper;
use Carbon\Carbon;
use NextDeveloper\Monitoring\Contracts\ListsMetricSeries;
use NextDeveloper\Monitoring\Contracts\ListsPlugins;
use NextDeveloper\Monitoring\Contracts\ManagesChecks;
use NextDeveloper\Monitoring\Contracts\ManagesMembers;
use NextDeveloper\Monitoring\Contracts\ManagesNotifications;
use NextDeveloper\Monitoring\Contracts\ManagesSites;
use NextDeveloper\Monitoring\DataTransferObjects\Alert;
use NextDeveloper\Monitoring\DataTransferObjects\AlertRoute;
use NextDeveloper\Monitoring\DataTransferObjects\Webhook;
use NextDeveloper\Monitoring\DataTransferObjects\Check;
use NextDeveloper\Monitoring\DataTransferObjects\CheckResult;
use NextDeveloper\Monitoring\DataTransferObjects\CheckState;
use NextDeveloper\Monitoring\DataTransferObjects\Host;
use NextDeveloper\Monitoring\DataTransferObjects\MetricSeries;
use NextDeveloper\Monitoring\DataTransferObjects\Site;
use NextDeveloper\Monitoring\Exceptions\ApiRequestFailed;
use NextDeveloper\Monitoring\Exceptions\UnsupportedOperation;
use NextDeveloper\Monitoring\Models\MonitoringTenant;
use NextDeveloper\Monitoring\MonitoringManager;

/**
 * Proxy between our API and the monitoring service, scoped to the current account.
 *
 * The caller never names a tenant or a server and never sees the platform key: the tenant is the
 * current account (UserHelper::currentAccount()), created on first use. The monitoring service
 * enforces the tenant boundary itself through the tenant header, so ids from another account
 * simply do not resolve (404). Webhooks are exposed only as "channels": one webhook plus the alert route that
 * feeds it, created and removed together, so a customer cannot end up with a webhook that never fires.
 */
class MonitoringProxyService
{
    public function __construct(
        private readonly MonitoringManager $manager,
        private readonly TenantService $tenants,
    ) {}

    /**
     * Tenant row of the current account; created remotely and locally on first use.
     * The external id is the account UUID, which is what the monitoring service keys tenants on.
     */
    public function tenant(): MonitoringTenant
    {
        $account = UserHelper::currentAccount();

        return $this->tenants->create(
            $account->id,
            (string) ($account->name ?: $account->uuid),
            null,
            ['external_id' => $account->uuid],
        );
    }

    public function tenantInfo(): array
    {
        $tenant = $this->tenant();

        return [
            'name' => $tenant->name,
            'status' => $tenant->status instanceof \BackedEnum ? $tenant->status->value : $tenant->status,
            'capabilities' => array_values(array_filter(
                ['tenants', 'hosts', 'checks', 'alerts', 'sites', 'metrics', 'notifications', 'plugins'],
                fn ($c) => $this->manager->forTenant($tenant)->supports($c),
            )),
        ];
    }

    // ---- hosts ----

    public function listHosts(array $filters = []): array
    {
        [$driver, $id] = $this->context();

        return $driver->listHosts($id, $filters)->map(fn (Host $h) => $this->host($h))->values()->all();
    }

    public function getHost(string $hostId): array
    {
        [$driver, $id] = $this->context();

        return $this->host($driver->getHost($id, $hostId));
    }

    /** $data: name, type, address?, tags?, notes?, site_id?, parent_id?, external_id?, external_type? */
    public function createHost(array $data): array
    {
        [$driver, $id] = $this->context();

        $host = new Host(
            null,
            $data['name'],
            $data['address'] ?? null,
            tags: $data['tags'] ?? [],
            raw: array_intersect_key($data, array_flip(['notes', 'site_id', 'parent_id', 'physical_peer_id'])),
            type: $data['type'],
            externalId: $data['external_id'] ?? null,
            externalType: $data['external_type'] ?? null,
        );

        return $this->host($driver->createHost($id, $host));
    }

    /** Partial update (merge patch): omitted fields stay, null clears, tags merge key by key. */
    public function updateHost(string $hostId, array $data): array
    {
        [$driver, $id] = $this->context();

        return $this->host($driver->updateHost($id, $hostId, $data));
    }

    public function deleteHost(string $hostId): void
    {
        [$driver, $id] = $this->context();

        $driver->deleteHost($id, $hostId);
    }

    /** Runs every enabled check of the host once, synchronously; nothing is stored. */
    public function testHost(string $hostId): array
    {
        [$driver, $id] = $this->context();

        return $this->checksDriver($driver)->testHost($id, $hostId)
            ->map(fn (CheckResult $r) => $r->toArray())->values()->all();
    }

    // ---- checks ----

    public function listChecks(?string $hostId = null, array $filters = []): array
    {
        [$driver, $id] = $this->context();

        return $this->checksDriver($driver)->listChecks($id, $hostId, $filters)
            ->map(fn (Check $c) => $this->check($c))->values()->all();
    }

    public function getCheck(string $checkId): array
    {
        [$driver, $id] = $this->context();

        return $this->check($this->checksDriver($driver)->getCheck($id, $checkId));
    }

    /** $data: name, plugin, config?, interval_seconds?, enabled?, thresholds?, is_host_check?, plus server tuning fields. */
    public function createCheck(string $hostId, array $data): array
    {
        [$driver, $id] = $this->context();

        $check = new Check(
            null,
            $hostId,
            $data['name'],
            $data['plugin'],
            $data['config'] ?? [],
            $data['interval_seconds'] ?? null,
            $data['enabled'] ?? true,
            $data['thresholds'] ?? [],
            $data['is_host_check'] ?? false,
            array_intersect_key($data, array_flip(['timeout_seconds', 'failure_count', 'recovery_count', 'unknown_is_critical', 'runbook_url'])),
        );

        return $this->check($this->checksDriver($driver)->createCheck($id, $check));
    }

    public function updateCheck(string $checkId, array $data): array
    {
        [$driver, $id] = $this->context();

        return $this->check($this->checksDriver($driver)->updateCheck($id, $checkId, $data));
    }

    public function deleteCheck(string $checkId): void
    {
        [$driver, $id] = $this->context();

        $this->checksDriver($driver)->deleteCheck($id, $checkId);
    }

    /** Null before the first result. */
    public function checkState(string $checkId): ?array
    {
        [$driver, $id] = $this->context();

        return $this->checksDriver($driver)->getCheckState($id, $checkId)?->toArray();
    }

    public function runCheck(string $checkId): void
    {
        [$driver, $id] = $this->context();

        $this->checksDriver($driver)->runCheckNow($id, $checkId);
    }

    // ---- alerts (incidents) ----

    /** Filters: status (open|acknowledged|resolved|active), severity, host_id, check_id. */
    public function listAlerts(array $filters = []): array
    {
        [$driver, $id] = $this->context();

        return $driver->listAlerts($id, $filters)->map(fn (Alert $a) => $this->alert($a))->values()->all();
    }

    public function acknowledgeAlert(string $alertId, ?string $note = null): array
    {
        [$driver, $id] = $this->context();

        return $this->alert($driver->acknowledgeAlert($id, $alertId, $note));
    }

    public function resolveAlert(string $alertId): array
    {
        [$driver, $id] = $this->context();

        return $this->alert($driver->resolveAlert($id, $alertId));
    }

    // ---- sites ----

    public function listSites(): array
    {
        [$driver, $id] = $this->context();

        return $this->sitesDriver($driver)->listSites($id)->map(fn (Site $s) => $this->site($s))->values()->all();
    }

    /** A site has no source object in our system, so its external id is generated here. */
    public function createSite(array $data): array
    {
        [$driver, $id] = $this->context();

        $site = new Site(null, $data['name'], $data['country'] ?? null, $data['timezone'] ?? null, $data['address'] ?? null, (string) Str::uuid(), Site::class);

        return $this->site($this->sitesDriver($driver)->upsertSite($id, $site));
    }

    /** Fails with 409 while devices are still in the site. */
    public function deleteSite(string $siteId): void
    {
        [$driver, $id] = $this->context();

        $this->sitesDriver($driver)->deleteSite($id, $siteId);
    }

    // ---- plugins (check types) ----

    /**
     * The check types the monitoring service offers, so the UI builds check forms from this instead of hard-coding them.
     * `config_schema` is a JSON Schema for the check's `config`; `credential_types` are the credential kinds a check of
     * this type can use (empty: none). `min_interval_seconds` is the plugin's own floor; customer tenants have a higher
     * floor of their own, which the monitoring service enforces with a 422.
     */
    public function plugins(): array
    {
        [$driver, $id] = $this->context();

        if (! $driver instanceof ListsPlugins) {
            throw UnsupportedOperation::for($driver->driverName(), 'plugins');
        }

        return $driver->listPlugins($id)->map(fn (array $p) => [
            'type' => $p['type'] ?? null,
            'description' => $p['description'] ?? null,
            'kind' => $p['kind'] ?? null,
            'default_interval_seconds' => $p['default_interval_seconds'] ?? null,
            'min_interval_seconds' => $p['min_interval_seconds'] ?? null,
            'config_schema' => $p['config_schema'] ?? null,
            'metrics' => collect($p['metrics'] ?? [])->map(fn (array $m) => [
                'name' => $m['name'] ?? null,
                'unit' => $m['unit'] ?? null,
                'kind' => $m['kind'] ?? null,
                'description' => $m['description'] ?? null,
            ])->values()->all(),
            'credential_types' => $p['credential_types'] ?? [],
        ])->values()->all();
    }

    // ---- metrics (stored check metrics, read only) ----

    /**
     * Graph data for one host. $params: name[] (metric names), from, to (default: last hour), step (seconds),
     * agg (avg|min|max|sum), check_id, object. When no step is given it is chosen from the range so a graph
     * stays around a few hundred points (the server caps a series at 10,000). Empty buckets are omitted: a gap is no data.
     */
    public function metrics(string $hostId, array $params = []): array
    {
        [$driver, $id] = $this->context();

        $to = isset($params['to']) ? Carbon::parse($params['to']) : Carbon::now();
        $from = isset($params['from']) ? Carbon::parse($params['from']) : $to->copy()->subHour();

        $options = array_filter([
            'step' => $params['step'] ?? $this->defaultStep($from, $to),
            'agg' => $params['agg'] ?? null,
            'check_id' => $params['check_id'] ?? null,
            'object' => $params['object'] ?? null,
        ], fn ($v) => $v !== null);

        $series = $driver->getMetrics($id, $hostId, $params['name'] ?? [], $from, $to, $options);

        return [
            'from' => $from->toAtomString(),
            'to' => $to->toAtomString(),
            'step' => $options['step'],
            'resolution' => $series->first()?->raw['resolution'] ?? null,
            'series' => $series->map(fn (MetricSeries $s) => [
                'name' => $s->key,
                'unit' => $s->unit,
                'object' => $s->object,
                'check_id' => $s->raw['check_id'] ?? null,
                'points' => $s->points->map(fn ($p) => ['t' => $p->timestamp?->format(DATE_ATOM), 'v' => $p->value])->values()->all(),
            ])->values()->all(),
        ];
    }

    /** Which metric series exist for a host (or one of its checks), so the client can offer graphs. */
    public function metricSeries(string $hostId, array $params = []): array
    {
        [$driver, $id] = $this->context();

        if (! $driver instanceof ListsMetricSeries) {
            throw UnsupportedOperation::for($driver->driverName(), 'metric series');
        }

        return $driver->listMetricSeries($id, $hostId, $params['check_id'] ?? null, $params['name'] ?? [])
            ->map(fn (array $s) => [
                'name' => $s['name'] ?? null,
                'unit' => $s['unit'] ?? null,
                'kind' => $s['kind'] ?? null,
                'plugin' => $s['plugin'] ?? null,
                'object' => $s['object'] ?? null,
                'check_id' => $s['check_id'] ?? null,
            ])->values()->all();
    }

    /** Step for a range, matching what the monitoring service suggests: 30 s up to 1 h, 5 min up to 24 h, 1 h up to 30 d, else 1 day. */
    private function defaultStep(Carbon $from, Carbon $to): int
    {
        $seconds = max(1, $to->diffInSeconds($from));

        return match (true) {
            $seconds <= 3600 => 30,
            $seconds <= 86400 => 300,
            $seconds <= 30 * 86400 => 3600,
            default => 86400,
        };
    }

    // ---- notification channels (webhook + alert route) ----

    /** Marks webhooks and routes created here, so channels never mix with anything else on the tenant. */
    private const CHANNEL_TYPE = 'PlusClouds\\Monitoring\\Channel';

    private const CHANNEL_ROUTE_TYPE = 'PlusClouds\\Monitoring\\ChannelRoute';

    public function listChannels(): array
    {
        [$driver, $id] = $this->context();
        $notifications = $this->notificationsDriver($driver);

        $routes = $notifications->listAlertRoutes($id)->keyBy(fn (AlertRoute $r) => $r->webhookId);

        return $notifications->listWebhooks($id)
            ->filter(fn (Webhook $w) => $w->externalType === self::CHANNEL_TYPE)
            ->map(fn (Webhook $w) => $this->channel($w, $routes->get($w->id)))
            ->values()->all();
    }

    /**
     * Creates the webhook and its route. The signing secret is in the result once and is never returned again;
     * the customer has to store it. If the route cannot be created the webhook is removed again.
     *
     * $data: name, url, enabled?, timeout_seconds?, headers?, severity[]?, device_types[]?, tags{}?
     */
    public function createChannel(array $data): array
    {
        [$driver, $id] = $this->context();
        $notifications = $this->notificationsDriver($driver);

        $this->assertDeliverableUrl($data['url']);

        $externalId = (string) Str::uuid();

        $webhook = $notifications->upsertWebhook($id, new Webhook(
            null, $data['name'], $data['url'], $data['enabled'] ?? true, $data['timeout_seconds'] ?? null,
            $data['headers'] ?? [], $externalId, self::CHANNEL_TYPE,
        ));

        try {
            $route = $notifications->upsertAlertRoute($id, new AlertRoute(
                null, $data['name'], $webhook->id, match: $this->routeMatch($data),
                enabled: $data['enabled'] ?? true, externalId: $externalId, externalType: self::CHANNEL_ROUTE_TYPE,
            ));
        } catch (\Throwable $e) {
            $notifications->deleteWebhook($id, $webhook->id);

            throw $e;
        }

        return $this->channel($webhook, $route) + ['secret' => $webhook->secret];
    }

    /** Partial update. Header values are write-only: leave `headers` out to keep them, send {"Name": null} to drop one. */
    public function updateChannel(string $channelId, array $data): array
    {
        [$driver, $id] = $this->context();
        $notifications = $this->notificationsDriver($driver);

        $current = $this->findChannelWebhook($notifications, $id, $channelId);
        $route = $notifications->listAlertRoutes($id)->first(fn (AlertRoute $r) => $r->webhookId === $current->id);

        if (isset($data['url'])) {
            $this->assertDeliverableUrl($data['url']);
        }

        $enabled = $data['enabled'] ?? $current->enabled;

        $webhook = $notifications->upsertWebhook($id, new Webhook(
            null, $data['name'] ?? $current->name, $data['url'] ?? $current->url, $enabled,
            $data['timeout_seconds'] ?? $current->timeoutSeconds, $data['headers'] ?? [], $current->externalId, self::CHANNEL_TYPE,
        ));

        $match = $route?->match ?? [];
        foreach (['severity', 'device_types', 'tags'] as $key) {
            if (array_key_exists($key, $data)) {
                $match = $this->routeMatch([$key => $data[$key]]) + $match;
            }
        }

        $route = $notifications->upsertAlertRoute($id, new AlertRoute(
            $route?->id, $data['name'] ?? $current->name, $webhook->id, $route?->position, $enabled,
            array_filter($match, fn ($v) => $v !== [] && $v !== null),
            externalId: $route?->externalId ?? $current->externalId, externalType: self::CHANNEL_ROUTE_TYPE,
        ));

        return $this->channel($webhook, $route);
    }

    public function deleteChannel(string $channelId): void
    {
        [$driver, $id] = $this->context();
        $notifications = $this->notificationsDriver($driver);

        $webhook = $this->findChannelWebhook($notifications, $id, $channelId);

        foreach ($notifications->listAlertRoutes($id)->filter(fn (AlertRoute $r) => $r->webhookId === $webhook->id) as $route) {
            $notifications->deleteAlertRoute($id, $route->id);
        }

        $notifications->deleteWebhook($id, $webhook->id);
    }

    /** Sends a test event to the customer's URL. A blocked or unreachable URL shows up here as ok=false. */
    public function testChannel(string $channelId): array
    {
        [$driver, $id] = $this->context();
        $notifications = $this->notificationsDriver($driver);

        $webhook = $this->findChannelWebhook($notifications, $id, $channelId);
        $result = $notifications->testWebhook($id, $webhook->id);

        // The receiver's response body is the customer's own data, but cap it: it can be a whole HTML page.
        $result['response'] = isset($result['response']) ? Str::limit($result['response'], 500) : null;

        return $result;
    }

    /** The new secret is returned once. The old one stays valid for 24 hours, and deliveries carry both signatures. */
    public function rotateChannelSecret(string $channelId): array
    {
        [$driver, $id] = $this->context();
        $notifications = $this->notificationsDriver($driver);

        $webhook = $this->findChannelWebhook($notifications, $id, $channelId);

        return ['secret' => $notifications->rotateWebhookSecret($id, $webhook->id), 'previous_secret_valid_hours' => 24];
    }

    /** Delivery attempts, newest first. $status: pending|delivered|failed|cancelled. */
    public function channelDeliveries(string $channelId, ?string $status = null): array
    {
        [$driver, $id] = $this->context();
        $notifications = $this->notificationsDriver($driver);

        $webhook = $this->findChannelWebhook($notifications, $id, $channelId);

        return $notifications->listWebhookDeliveries($id, $webhook->id, $status)->map(fn (array $d) => [
            'id' => $d['id'] ?? null,
            'event_id' => $d['event_id'] ?? null,
            'event_type' => $d['event_type'] ?? null,
            'subject' => $d['subject'] ?? null,
            'status' => $d['status'] ?? null,
            'attempts' => $d['attempts'] ?? null,
            'last_status_code' => $d['last_status_code'] ?? null,
            'last_error' => $d['last_error'] ?? null,
            'delivered_at' => $d['delivered_at'] ?? null,
            'created_at' => $d['created_at'] ?? null,
        ])->values()->all();
    }

    public function replayChannelDelivery(string $channelId, string $deliveryId): void
    {
        [$driver, $id] = $this->context();
        $notifications = $this->notificationsDriver($driver);

        $webhook = $this->findChannelWebhook($notifications, $id, $channelId);

        $notifications->replayWebhookDelivery($id, $webhook->id, $deliveryId);
    }

    /**
     * Early, best-effort check so the customer gets an error now instead of a failed delivery later.
     * The monitoring service blocks internal targets itself at connect time (after DNS), and that stays the real defence.
     */
    private function assertDeliverableUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        if (! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || $host === '') {
            throw new \InvalidArgumentException('The webhook URL must be an http or https URL.');
        }

        $internalName = $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal');
        $privateLiteral = filter_var($host, FILTER_VALIDATE_IP)
            && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

        if ($internalName || $privateLiteral) {
            throw new \InvalidArgumentException('The webhook URL must point to a public address; private and local addresses cannot be reached.');
        }
    }

    /** @return array<string, mixed> the route match built from the channel fields given */
    private function routeMatch(array $data): array
    {
        return array_filter([
            'severity' => $data['severity'] ?? null,
            'device_types' => $data['device_types'] ?? null,
            'tags' => $data['tags'] ?? null,
        ], fn ($v) => $v !== null && $v !== []);
    }

    private function findChannelWebhook(ManagesNotifications $notifications, string $tenantId, string $channelId): Webhook
    {
        $webhook = $notifications->listWebhooks($tenantId)
            ->first(fn (Webhook $w) => $w->id === $channelId && $w->externalType === self::CHANNEL_TYPE);

        return $webhook ?? throw new ApiRequestFailed('Channel not found.', 404, ['type' => 'not-found', 'detail' => 'Not found']);
    }

    private function notificationsDriver($driver): ManagesNotifications
    {
        return $driver instanceof ManagesNotifications ? $driver : throw UnsupportedOperation::for($driver->driverName(), 'notifications');
    }

    private function channel(Webhook $w, ?AlertRoute $route): array
    {
        return [
            'id' => $w->id,
            'name' => $w->name,
            'url' => $w->url,
            'enabled' => $w->enabled,
            'disabled_reason' => $w->disabledReason,
            'timeout_seconds' => $w->timeoutSeconds,
            'header_names' => $w->raw['header_names'] ?? [],
            'severity' => $route?->match['severity'] ?? [],
            'device_types' => $route?->match['device_types'] ?? [],
            'tags' => $route?->match['tags'] ?? [],
            'previous_secret_valid_until' => $w->raw['previous_valid_until'] ?? null,
        ];
    }

    // ---- internals ----

    /**
     * @return array{0: \NextDeveloper\Monitoring\Contracts\MonitoringDriver, 1: string} driver and the tenant id to call it with.
     * The driver acts as the current user, so the monitoring service applies that user's role (and audits their name).
     */
    private function context(): array
    {
        $tenant = $this->tenant();
        $driver = $this->manager->forTenant($tenant);

        if ($driver instanceof ManagesMembers) {
            $driver = $this->actingAsCurrentUser($driver, $tenant);
        }

        return [$driver, $tenant->external_tenant_id];
    }

    /**
     * Make sure the current user is a member of the tenant with the role their account role maps to, then act as them.
     * The monitoring service creates an unknown actor as read-only and never changes an existing member's role, so we
     * set it ourselves. The role is cached for a few minutes; a role change takes effect when the cache expires.
     */
    private function actingAsCurrentUser(ManagesMembers $driver, MonitoringTenant $tenant): ManagesMembers
    {
        $user = UserHelper::me();

        if (! $user) {
            return $driver;
        }

        $role = $this->monitoringRole();
        $cacheKey = "monitoring:member:{$tenant->external_tenant_id}:{$user->uuid}";

        if (Cache::get($cacheKey) !== $role) {
            $driver->upsertMember($tenant->external_tenant_id, $user->uuid, $role);
            Cache::put($cacheKey, $role, now()->addMinutes(10));
        }

        return $driver->actingAs($user->uuid);
    }

    /**
     * Operator when the user holds any of monitoring.operator_roles (monitoring-manager, monitoring-admin and, for now,
     * cloud-resource-owner); everybody else is read-only. Never admin: that is the monitoring service owner's.
     */
    private function monitoringRole(): string
    {
        foreach (config('monitoring.operator_roles', []) as $role) {
            if (UserHelper::hasRole($role)) {
                return ManagesMembers::ROLE_OPERATOR;
            }
        }

        return ManagesMembers::ROLE_READ_ONLY;
    }

    private function checksDriver($driver): ManagesChecks
    {
        return $driver instanceof ManagesChecks ? $driver : throw UnsupportedOperation::for($driver->driverName(), 'checks');
    }

    private function sitesDriver($driver): ManagesSites
    {
        return $driver instanceof ManagesSites ? $driver : throw UnsupportedOperation::for($driver->driverName(), 'sites');
    }

    // Response shapes: only what the client needs; raw server fields are picked, never passed through whole.

    private function host(Host $h): array
    {
        return [
            'id' => $h->id,
            'name' => $h->name,
            'address' => $h->address,
            'type' => $h->type,
            'tags' => $h->tags,
            'notes' => $h->raw['notes'] ?? null,
            'site_id' => $h->raw['site_id'] ?? null,
            'parent_id' => $h->raw['parent_id'] ?? null,
            'external_id' => $h->externalId,
            'status' => $h->raw['status'] ?? ['availability' => $h->status->value],
        ];
    }

    private function check(Check $c): array
    {
        return $c->toArray() + [
            'timeout_seconds' => $c->raw['timeout_seconds'] ?? null,
            'failure_count' => $c->raw['failure_count'] ?? null,
            'recovery_count' => $c->raw['recovery_count'] ?? null,
            'runbook_url' => $c->raw['runbook_url'] ?? null,
        ];
    }

    private function alert(Alert $a): array
    {
        return [
            'id' => $a->id,
            'summary' => $a->title,
            'severity' => $a->severity->value,
            'status' => $a->status->value,
            'host_id' => $a->hostId,
            'check_id' => $a->raw['check_id'] ?? null,
            'last_output' => $a->raw['last_output'] ?? null,
            'opened_at' => $a->startedAt?->format(DATE_ATOM),
            'acknowledged_at' => $a->raw['acknowledged_at'] ?? null,
            'resolved_at' => $a->raw['resolved_at'] ?? null,
            'resolved_by' => $a->raw['resolved_by'] ?? null,
        ];
    }

    private function site(Site $s): array
    {
        return ['id' => $s->id, 'name' => $s->name, 'country' => $s->country, 'timezone' => $s->timezone, 'address' => $s->address];
    }
}
