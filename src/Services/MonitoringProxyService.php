<?php

namespace NextDeveloper\Monitoring\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use NextDeveloper\IAM\Helpers\UserHelper;
use Carbon\Carbon;
use NextDeveloper\Monitoring\Contracts\ListsMetricSeries;
use NextDeveloper\Monitoring\Contracts\ListsCheckObjects;
use NextDeveloper\Monitoring\Contracts\ListsPlugins;
use NextDeveloper\Monitoring\Contracts\ManagesChecks;
use NextDeveloper\Monitoring\Contracts\ManagesMqtt;
use NextDeveloper\Monitoring\Contracts\RotatesPushTokens;
use NextDeveloper\Monitoring\Contracts\ManagesCredentials;
use NextDeveloper\Monitoring\Contracts\ManagesMembers;
use NextDeveloper\Monitoring\Contracts\ManagesNotifications;
use NextDeveloper\Monitoring\Contracts\GuardsTenantRestore;
use NextDeveloper\Monitoring\Contracts\ManagesSites;
use NextDeveloper\Monitoring\Contracts\ManagesWhoopsy;
use NextDeveloper\Monitoring\Contracts\SummarizesMetrics;
use NextDeveloper\Monitoring\Contracts\RestoresTenants;
use NextDeveloper\Monitoring\DataTransferObjects\Alert;
use NextDeveloper\Monitoring\DataTransferObjects\AlertRoute;
use NextDeveloper\Monitoring\DataTransferObjects\Webhook;
use NextDeveloper\Monitoring\DataTransferObjects\Check;
use NextDeveloper\Monitoring\DataTransferObjects\CheckResult;
use NextDeveloper\Monitoring\DataTransferObjects\CheckState;
use NextDeveloper\Monitoring\DataTransferObjects\Credential;
use NextDeveloper\Monitoring\DataTransferObjects\Host;
use NextDeveloper\Monitoring\DataTransferObjects\MetricSeries;
use NextDeveloper\Monitoring\DataTransferObjects\Site;
use NextDeveloper\Monitoring\Exceptions\ApiRequestFailed;
use NextDeveloper\Monitoring\Enums\TenantStatus;
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
        $tenant = $this->verifiedTenant();

        return [
            'name' => $tenant->name,
            'status' => $tenant->status instanceof \BackedEnum ? $tenant->status->value : $tenant->status,
            'capabilities' => array_values(array_filter(
                ['tenants', 'hosts', 'checks', 'alerts', 'sites', 'metrics', 'notifications', 'plugins', 'credentials', 'collectors', 'summary', 'whoopsy', 'push_tokens', 'mqtt'],
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
            array_intersect_key($data, array_flip(['timeout_seconds', 'failure_count', 'recovery_count', 'unknown_is_critical', 'runbook_url', 'credentials'])),
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

    /**
     * Push checks: issue a new ingest token. The response is the check including `push_token`, shown only this once;
     * the old token stops working at once. 409 for a polled check.
     */
    public function rotateCheckToken(string $checkId): array
    {
        [$driver, $id] = $this->context();

        if (! $driver instanceof RotatesPushTokens) {
            throw UnsupportedOperation::for($driver->driverName(), 'push_tokens');
        }

        return $this->check($driver->rotateCheckToken($id, $checkId));
    }

    // ---- MQTT ingest (devices send data to the monitoring service's MQTT broker) ----

    public function listMqttCredentials(): array
    {
        [$driver, $id] = $this->context();

        return $this->mqttDriver($driver)->listMqttCredentials($id)->map(fn (array $c) => $this->mqttCredential($c))->values()->all();
    }

    public function getMqttCredential(string $credentialId): array
    {
        [$driver, $id] = $this->context();

        return $this->mqttCredential($this->mqttDriver($driver)->getMqttCredential($id, $credentialId));
    }

    /** $data: name, kind (device|shared), username?, password?, device_key?, profile?, allow_plain?, auto_register?, enabled?. The response carries `password` once. */
    public function createMqttCredential(array $data): array
    {
        [$driver, $id] = $this->context();

        return $this->mqttCredential($this->mqttDriver($driver)->createMqttCredential($id, $data));
    }

    /** $data: name, allow_plain, auto_register, enabled. */
    public function updateMqttCredential(string $credentialId, array $data): array
    {
        [$driver, $id] = $this->context();

        return $this->mqttCredential($this->mqttDriver($driver)->updateMqttCredential($id, $credentialId, $data));
    }

    public function deleteMqttCredential(string $credentialId): void
    {
        [$driver, $id] = $this->context();

        $this->mqttDriver($driver)->deleteMqttCredential($id, $credentialId);
    }

    /** New password (generated when none is given); the old one stops at once. The response carries `password` once. */
    public function rotateMqttCredential(string $credentialId, ?string $password = null): array
    {
        [$driver, $id] = $this->context();

        return $this->mqttCredential($this->mqttDriver($driver)->rotateMqttCredential($id, $credentialId, $password));
    }

    /** The message profiles (fixlean-esp, json). */
    public function mqttProfiles(): array
    {
        [$driver, $id] = $this->context();

        return $this->mqttDriver($driver)->listMqttProfiles($id)->map(fn (array $p) => [
            'name' => $p['name'] ?? null,
            'description' => $p['description'] ?? null,
        ])->values()->all();
    }

    /** Device keys whose messages were dropped (auto-registration off, or the account is at its device limit). */
    public function mqttUnregistered(): array
    {
        [$driver, $id] = $this->context();

        return $this->mqttDriver($driver)->listMqttUnregistered($id)->map(fn (array $u) => [
            'device_key' => $u['device_key'] ?? null,
            'credential_id' => $u['credential_id'] ?? null,
            'reason' => $u['reason'] ?? null,
            'messages' => $u['messages'] ?? null,
            'first_seen' => $u['first_seen'] ?? null,
            'last_seen' => $u['last_seen'] ?? null,
        ])->values()->all();
    }

    /** Pre-registers a device key on a host. $data: device_key, profile?. 409 when already bound. */
    public function bindHostMqtt(string $hostId, array $data): array
    {
        [$driver, $id] = $this->context();

        return $this->hostMqtt($this->mqttDriver($driver)->bindHostMqtt($id, $hostId, $data));
    }

    /** 404 when the host is not an MQTT device. */
    public function getHostMqtt(string $hostId): array
    {
        [$driver, $id] = $this->context();

        return $this->hostMqtt($this->mqttDriver($driver)->getHostMqtt($id, $hostId));
    }

    /** Also removes the device's two checks. */
    public function unbindHostMqtt(string $hostId): void
    {
        [$driver, $id] = $this->context();

        $this->mqttDriver($driver)->unbindHostMqtt($id, $hostId);
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
            // advisory: true = the plugin needs a credential to work (checks without one run UNKNOWN); false = optional or none
            'credentials_required' => (bool) ($p['credentials_required'] ?? false),
            // the metric Whoopsy! watches by default; null: Whoopsy! is not available for this plugin (collectors, or no default metric)
            'whoopsy_metric' => $p['whoopsy_metric'] ?? null,
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
        $agg = $params['agg'] ?? null;

        // Percentiles and standard deviation come from raw samples only (about 7 days of them), so they always ask for
        // resolution raw and a step that keeps the series under the service's 10,000 points; avg, min, max and sum
        // keep the usual step by range and may use the coarser rollups for long ranges.
        $raw = $agg !== null && ! in_array($agg, ['avg', 'min', 'max', 'sum'], true);

        $options = array_filter([
            'step' => $params['step'] ?? ($raw ? $this->rawStep($from, $to) : $this->defaultStep($from, $to)),
            'agg' => $agg,
            'resolution' => $raw ? 'raw' : null,
            'moving_window' => $params['moving_window'] ?? null,
            'check_id' => $params['check_id'] ?? null,
            'object' => $params['object'] ?? null,
        ], fn ($v) => $v !== null);

        $series = $driver->getMetrics($id, $hostId, $params['name'] ?? [], $from, $to, $options);

        return [
            'from' => $from->toAtomString(),
            'to' => $to->toAtomString(),
            'step' => $options['step'],
            'resolution' => $series->first()?->raw['resolution'] ?? null,
            'agg' => $agg ?? 'avg',
            'series' => $series->map(fn (MetricSeries $s) => [
                'name' => $s->key,
                'unit' => $s->unit,
                'object' => $s->object,
                'check_id' => $s->raw['check_id'] ?? null,
                'points' => $s->points->map(fn ($p) => ['t' => $p->timestamp?->format(DATE_ATOM), 'v' => $p->value])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * One set of statistics per metric series over the whole window (default: the last hour): count, min, max, avg,
     * stddev, p50, p95, p99, any extra percentiles asked for, and the last value. Exact (from raw samples); a window
     * older than raw retention (about 7 days) answers 422 instead of an approximation.
     * $params: name[], check_id, object, from, to, percentile[] (p90, p99.9, up to 10), window (last N samples).
     */
    public function metricSummary(string $hostId, array $params = []): array
    {
        [$driver, $id] = $this->context();

        if (! $driver instanceof SummarizesMetrics) {
            throw UnsupportedOperation::for($driver->driverName(), 'metric summary');
        }

        $summary = $driver->summarizeMetrics(
            $id, $hostId, $params['name'] ?? [],
            isset($params['from']) ? Carbon::parse($params['from']) : null,
            isset($params['to']) ? Carbon::parse($params['to']) : null,
            array_intersect_key($params, array_flip(['check_id', 'object', 'percentile', 'window'])),
        );

        return [
            'from' => $summary['from'],
            'to' => $summary['to'],
            'resolution' => $summary['resolution'],
            'exact' => $summary['exact'],
            'series' => $summary['series']->map(fn (array $s) => [
                'name' => $s['name'] ?? null,
                'unit' => $s['unit'] ?? null,
                'object' => $s['object'] ?? null,
                'check_id' => $s['check_id'] ?? null,
                'count' => $s['count'] ?? 0,
                'min' => $s['min'] ?? null,
                'max' => $s['max'] ?? null,
                'avg' => $s['avg'] ?? null,
                'stddev' => $s['stddev'] ?? null,
                'p50' => $s['p50'] ?? null,
                'p95' => $s['p95'] ?? null,
                'p99' => $s['p99'] ?? null,
                'percentiles' => (object) ($s['percentiles'] ?? []),
                'last' => $s['last'] ?? null,
                'last_at' => $s['last_at'] ?? null,
                // statistics of the last N samples, only with window=N
                'moving' => $s['moving'] ?? null,
            ])->values()->all(),
        ];
    }

    /** Step for a raw-sample query: about 500 points, at least 10 s, never more than 9,000 points (the service caps a series at 10,000). */
    private function rawStep(Carbon $from, Carbon $to): int
    {
        $seconds = max(1, $to->diffInSeconds($from));

        return max(10, (int) ceil($seconds / 500));
    }

    /** Which metric series exist for a host (or one of its checks), so the client can offer graphs. */
    public function metricSeries(string $hostId, array $params = []): array
    {
        [$driver, $id] = $this->context();

        if (! $driver instanceof ListsMetricSeries) {
            throw UnsupportedOperation::for($driver->driverName(), 'metric series');
        }

        return $driver->listMetricSeries($id, $hostId, $params['check_id'] ?? null, $params['name'] ?? [], $params['object'] ?? null)
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

    // ---- credentials (what checks log in with: SNMP, HTTP auth, ...) ----

    /** The credential kinds and their fields. Secret fields are marked writeOnly in fields_schema. */
    public function credentialTypes(): array
    {
        [$driver, $id] = $this->context();

        return $this->credentialsDriver($driver)->listCredentialTypes($id)->map(fn (array $t) => [
            'name' => $t['name'] ?? null,
            'description' => $t['description'] ?? null,
            'fields_schema' => $t['fields_schema'] ?? null,
        ])->values()->all();
    }

    public function listCredentials(): array
    {
        [$driver, $id] = $this->context();

        return $this->credentialsDriver($driver)->listCredentials($id)->map(fn (Credential $c) => $this->credential($c))->values()->all();
    }

    /** $data: name, type, fields {username, password, community, ...}. The secrets are write-only: never returned. */
    public function createCredential(array $data): array
    {
        [$driver, $id] = $this->context();

        return $this->credential($this->credentialsDriver($driver)->createCredential($id, new Credential(null, $data['name'], $data['type'], $data['fields'] ?? [])));
    }

    /**
     * Partial update. Non-secret fields not sent keep their value (the server replaces on PUT, so the current ones are
     * sent along); a secret not sent keeps its stored value on the server. Send a secret only to change it.
     */
    public function updateCredential(string $credentialId, array $data): array
    {
        [$driver, $id] = $this->context();
        $credentials = $this->credentialsDriver($driver);

        $current = $credentials->getCredential($id, $credentialId);
        $fields = array_merge($current->fields, $data['fields'] ?? []);

        return $this->credential($credentials->updateCredential($id, $credentialId, new Credential(
            $credentialId, $data['name'] ?? $current->name, $data['type'] ?? $current->type, $fields,
        )));
    }

    /** Refused with 409 in-use while a check uses it. */
    public function deleteCredential(string $credentialId): void
    {
        [$driver, $id] = $this->context();

        $this->credentialsDriver($driver)->deleteCredential($id, $credentialId);
    }

    private function credentialsDriver($driver): ManagesCredentials
    {
        return $driver instanceof ManagesCredentials ? $driver : throw UnsupportedOperation::for($driver->driverName(), 'credentials');
    }

    private function credential(Credential $c): array
    {
        return ['id' => $c->id, 'name' => $c->name, 'type' => $c->type, 'fields' => (object) $c->fields, 'secrets_set' => $c->secretsSet];
    }

    // ---- Whoopsy! (premium alerting: alert when a metric leaves its own band) ----

    /**
     * Whoopsy! status of a check, with what it costs. `billing.applies` is true while it is on: the check is then billed
     * at `billed_weight` (its plugin's weight times the multiplier) instead of `plugin_weight`.
     */
    public function whoopsy(string $checkId): array
    {
        [$driver, $id] = $this->context();
        $whoopsy = $this->whoopsyDriver($driver);

        return $this->whoopsyStatus($whoopsy, $id, $checkId, $whoopsy->getWhoopsy($id, $checkId));
    }

    /**
     * Turn Whoopsy! on or change its settings. Turning it ON changes the price of the check, so it needs the caller's
     * explicit `confirm_price` = true; without it the answer is 422 stating the price. Changing the settings of a check
     * that already has it on needs no confirmation (the price does not change).
     *
     * $settings: metric, window (3..1000), deviations (>0..10), consecutive (1..100), direction (above|below|both),
     * min_delta, severity (warning|critical). Operators only (the monitoring service enforces it).
     */
    public function setWhoopsy(string $checkId, array $settings, bool $confirmPrice): array
    {
        [$driver, $id] = $this->context();
        $whoopsy = $this->whoopsyDriver($driver);

        $current = $whoopsy->getWhoopsy($id, $checkId);

        if (! ($current['enabled'] ?? false) && ! $confirmPrice) {
            $cost = $this->whoopsyCost($whoopsy, $id, $checkId);

            throw new \InvalidArgumentException(sprintf(
                'Whoopsy! is a premium feature: while it is on, this check is billed at %d times its normal price (weight %d instead of %d). Send confirm_price: true to turn it on.',
                $cost['multiplier'], $cost['billed_weight'], $cost['plugin_weight'],
            ));
        }

        return $this->whoopsyStatus($whoopsy, $id, $checkId, $whoopsy->setWhoopsy($id, $checkId, $settings));
    }

    public function disableWhoopsy(string $checkId): void
    {
        [$driver, $id] = $this->context();

        $this->whoopsyDriver($driver)->disableWhoopsy($id, $checkId);
    }

    /** Accept a new normal: the band is learned again from the next results, and an open Whoopsy! alert resolves. */
    public function resetWhoopsy(string $checkId): array
    {
        [$driver, $id] = $this->context();
        $whoopsy = $this->whoopsyDriver($driver);

        return $this->whoopsyStatus($whoopsy, $id, $checkId, $whoopsy->resetWhoopsy($id, $checkId));
    }

    /**
     * The band Whoopsy! used for each result in the window (default: the last hour), for drawing the moving average and
     * the normal range on a graph. Recorded by the engine when it judged the result, under the settings in force then, so
     * the drawn band is exactly what the result was compared with. At most 7 days and 20,000 points. Exists only from
     * the monitoring service v0.8.0 on: results before that have no recorded band.
     *
     * Each point: t, value, mean, stddev, lower, upper (null while learning; lower is null for direction "above",
     * upper is null for "below"), outside (the result broke the band), hits (results in a row outside, this one included),
     * alerting (Whoopsy! was alerting after this result).
     */
    public function whoopsyBand(string $checkId, ?string $from = null, ?string $to = null): array
    {
        [$driver, $id] = $this->context();

        return $this->whoopsyDriver($driver)->whoopsyBand($id, $checkId, $from ? Carbon::parse($from) : null, $to ? Carbon::parse($to) : null);
    }

    private function whoopsyDriver($driver): ManagesWhoopsy
    {
        return $driver instanceof ManagesWhoopsy ? $driver : throw UnsupportedOperation::for($driver->driverName(), 'whoopsy');
    }

    /** @return array{multiplier: int, plugin_weight: int, billed_weight: int} */
    private function whoopsyCost(ManagesWhoopsy $driver, string $tenantId, string $checkId): array
    {
        $pricing = $driver->whoopsyPricing($tenantId);
        $plugin = $this->getCheck($checkId)['plugin'] ?? '';
        $weight = (int) ($pricing['weights'][$plugin] ?? $pricing['default_weight']);

        return ['multiplier' => $pricing['multiplier'], 'plugin_weight' => $weight, 'billed_weight' => $weight * $pricing['multiplier']];
    }

    private function whoopsyStatus(ManagesWhoopsy $driver, string $tenantId, string $checkId, array $status): array
    {
        $cost = $this->whoopsyCost($driver, $tenantId, $checkId);

        return [
            'check_id' => $status['check_id'] ?? $checkId,
            'enabled' => (bool) ($status['enabled'] ?? false),
            'settings' => $status['settings'] ?? null,
            // the learned normal: points seen, mean, stddev, lower and upper limit, last value, hits in a row, alerting
            'band' => $status['band'] ?? null,
            'billing' => $cost + ['applies' => (bool) ($status['enabled'] ?? false)],
        ];
    }

    // ---- collector objects (interfaces of a switch, outlets of a PDU, ...) ----

    /**
     * The objects a collector check reports, each with its own state and incident. Empty for a plain check.
     * Objects the device stopped reporting are kept for 30 days (gone_at is set).
     */
    public function checkObjects(string $checkId, ?string $status = null, bool $includeGone = true): array
    {
        [$driver, $id] = $this->context();

        if (! $driver instanceof ListsCheckObjects) {
            throw UnsupportedOperation::for($driver->driverName(), 'check objects');
        }

        return $driver->listCheckObjects($id, $checkId, $status, $includeGone)->map(fn (array $o) => [
            'key' => $o['key'] ?? null,
            'name' => $o['name'] ?? null,
            'labels' => (object) ($o['labels'] ?? []),
            'phase' => $o['phase'] ?? null,
            'status' => $o['status'] ?? null,
            'since' => $o['since'] ?? null,
            'last_output' => $o['last_output'] ?? null,
            'last_metrics' => (object) ($o['last_metrics'] ?? []),
            'incident_id' => $o['incident_id'] ?? null,
            // the discovered device the object belongs to (a VM), when it is not the check's own host
            'host_id' => $o['device_id'] ?? null,
            'first_seen_at' => $o['first_seen_at'] ?? null,
            'last_seen_at' => $o['last_seen_at'] ?? null,
            'gone_at' => $o['gone_at'] ?? null,
        ])->values()->all();
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
                groupBy: $data['group_by'] ?? [], groupWaitSeconds: $data['group_wait_seconds'] ?? null,
                repeatIntervalSeconds: $data['repeat_interval_seconds'] ?? null, steps: $data['steps'] ?? [],
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
        foreach (['severity', 'device_types', 'tags', 'site_ids', 'check_ids', 'event_types'] as $key) {
            if (array_key_exists($key, $data)) {
                $match = $this->routeMatch([$key => $data[$key]]) + $match;
            }
        }

        $route = $notifications->upsertAlertRoute($id, new AlertRoute(
            $route?->id, $data['name'] ?? $current->name, $webhook->id, $route?->position, $enabled,
            array_filter($match, fn ($v) => $v !== [] && $v !== null),
            externalId: $route?->externalId ?? $current->externalId, externalType: self::CHANNEL_ROUTE_TYPE,
            // Route options keep their current value unless the request names them (null clears repeat_interval_seconds).
            groupBy: array_key_exists('group_by', $data) ? $data['group_by'] : ($route?->groupBy ?? []),
            groupWaitSeconds: array_key_exists('group_wait_seconds', $data) ? $data['group_wait_seconds'] : $route?->groupWaitSeconds,
            repeatIntervalSeconds: array_key_exists('repeat_interval_seconds', $data) ? $data['repeat_interval_seconds'] : $route?->repeatIntervalSeconds,
            steps: array_key_exists('steps', $data) ? $data['steps'] : ($route?->steps ?? []),
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
     * Which of the account's channels a sample incident would notify (event_type, severity, host_id or check_id).
     * Routes come back in evaluation order with matched (the filters fit) and notifies (it would actually send).
     */
    public function previewChannels(array $sample): array
    {
        [$driver, $id] = $this->context();
        $notifications = $this->notificationsDriver($driver);

        $body = array_filter([
            'event_type' => $sample['event_type'] ?? null,
            'severity' => $sample['severity'] ?? null,
            'device_id' => $sample['host_id'] ?? null,
            'check_id' => $sample['check_id'] ?? null,
        ], fn ($v) => $v !== null);

        $channels = $notifications->listWebhooks($id)->filter(fn (Webhook $w) => $w->externalType === self::CHANNEL_TYPE)->keyBy('id');
        $routes = $notifications->listAlertRoutes($id)->keyBy('id');

        return $notifications->previewAlertRoutes($id, $body)
            ->map(function (array $r) use ($channels, $routes) {
                $channel = $channels->get($routes->get($r['id'] ?? '')?->webhookId ?? $r['endpoint_id'] ?? '');

                return ['channel_id' => $channel?->id, 'name' => $r['name'] ?? null, 'matched' => (bool) ($r['matched'] ?? false), 'notifies' => (bool) ($r['notifies'] ?? false)];
            })
            ->filter(fn (array $r) => $r['channel_id'] !== null)
            ->values()->all();
    }

    /** Send a channel's failed (or cancelled) deliveries again, in bulk, for a time range. */
    public function replayChannelDeliveries(string $channelId, string $from, string $to, string $status = 'failed'): void
    {
        [$driver, $id] = $this->context();
        $notifications = $this->notificationsDriver($driver);

        $webhook = $this->findChannelWebhook($notifications, $id, $channelId);

        $notifications->replayWebhookDeliveries($id, $webhook->id, Carbon::parse($from), Carbon::parse($to), $status);
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
            'site_ids' => $data['site_ids'] ?? null,
            'check_ids' => $data['check_ids'] ?? null,
            'event_types' => $data['event_types'] ?? null,
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
            'site_ids' => $route?->match['site_ids'] ?? [],
            'check_ids' => $route?->match['check_ids'] ?? [],
            'event_types' => $route?->match['event_types'] ?? [],
            // grouping: incidents sharing these fields arrive as one event after group_wait_seconds
            'group_by' => $route?->groupBy ?? [],
            'group_wait_seconds' => $route?->groupWaitSeconds,
            // resend open, unacknowledged incidents this often (seconds); null: never
            'repeat_interval_seconds' => $route?->repeatIntervalSeconds,
            // escalation steps: [{after_seconds, labels, only_if_unacknowledged, schedule}]
            'steps' => $route?->steps ?? [],
            'previous_secret_valid_until' => $w->raw['previous_valid_until'] ?? null,
        ];
    }

    // ---- internals ----

    /**
     * The local tenant row, after checking that the monitoring service still has the tenant and it is usable.
     * The local row is created once and trusted afterwards, so it can go stale: the service may have soft-deleted the
     * tenant (its devices and checks are then unreachable, and every call answers 404 "Not found") or purged it (gone).
     * The check is cached for five minutes per tenant.
     *
     *  - active or suspended: fine (a suspended tenant refuses writes itself)
     *  - deleted: restored automatically and emptied (see recoverDeletedTenant); only if the host application allows it
     *  - gone (purged, or the service was reset): create it again, empty, so the customer is not locked out
     */
    private function verifiedTenant(): MonitoringTenant
    {
        $tenant = $this->tenant();
        $cacheKey = "monitoring:tenant-verified:{$tenant->external_tenant_id}";

        if (Cache::get($cacheKey)) {
            return $tenant;
        }

        $driver = $this->manager->forTenant($tenant);

        try {
            $remote = $driver->getTenant($tenant->external_tenant_id);
        } catch (ApiRequestFailed $e) {
            if ($e->status !== 404) {
                throw $e;
            }

            // Not on the service any more: re-provision with the same external id (idempotent).
            $driver->createTenant($tenant->name, ['external_id' => $tenant->external_tenant_id]);
            Log::warning("[Monitoring] tenant {$tenant->external_tenant_id} was missing on the monitoring service and was created again.");
            $remote = null;
        }

        if ($remote && $remote->status === TenantStatus::Deleted) {
            $this->recoverDeletedTenant($tenant, $driver);
        } elseif (! empty($tenant->meta['wipe_pending'])) {
            // A previous recovery restored the tenant but did not finish emptying it: finish now.
            $this->emptyRestoredTenant($tenant, $driver);
        }

        Cache::put($cacheKey, true, now()->addMinutes(5));

        return $tenant;
    }

    /**
     * Restore a deleted tenant and empty it, so the customer is not locked out and nothing from before comes back.
     * The tenant had been removed on the monitoring service, and a plain restore would bring back every host, check and
     * channel and start the checks (and their billing) again, so the old items are deleted right after the restore.
     *
     * Refused (409 tenant-deleted) when the driver cannot restore, or the host application's guard vetoes it (for
     * example a suspended account). One request does the work at a time; the others wait for it. If emptying fails
     * midway, the tenant is marked and the next call finishes the job.
     */
    private function recoverDeletedTenant(MonitoringTenant $tenant, $driver): void
    {
        $guard = app()->bound(GuardsTenantRestore::class) ? app(GuardsTenantRestore::class) : null;

        if (! $driver instanceof RestoresTenants || ($guard && ! $guard->allowsRestore($tenant))) {
            Log::warning("[Monitoring] tenant {$tenant->external_tenant_id} is deleted on the monitoring service and is not restored automatically.");

            throw new ApiRequestFailed('Tenant is deleted on the monitoring service.', 409, [
                'type' => 'https://monitor.plusclouds.com/problems/tenant-deleted',
                'detail' => 'Monitoring was removed for this account. Contact support to restore it.',
            ]);
        }

        Cache::lock("monitoring:restore:{$tenant->external_tenant_id}", 120)->block(30, function () use ($tenant, $driver) {
            // Another request may have done it while this one waited.
            if ($driver->getTenant($tenant->external_tenant_id)->status !== TenantStatus::Deleted) {
                return;
            }

            // Marked first: if anything below fails, the next call finishes emptying the tenant.
            $tenant->update(['meta' => array_merge($tenant->meta ?? [], ['wipe_pending' => true])]);

            $driver->restoreTenant($tenant->external_tenant_id);
            Log::warning("[Monitoring] tenant {$tenant->external_tenant_id} was deleted on the monitoring service and was restored automatically.");

            $this->emptyRestoredTenant($tenant, $driver);
        });
    }

    private function emptyRestoredTenant(MonitoringTenant $tenant, $driver): void
    {
        app(TenantRecoveryService::class)->empty($driver, $tenant->external_tenant_id);

        $meta = $tenant->meta ?? [];
        unset($meta['wipe_pending']);
        $tenant->update(['meta' => array_merge($meta, ['restored_at' => now()->toAtomString()])]);
    }

    /**
     * @return array{0: \NextDeveloper\Monitoring\Contracts\MonitoringDriver, 1: string} driver and the tenant id to call it with.
     * The driver acts as the current user, so the monitoring service applies that user's role (and audits their name).
     */
    private function context(): array
    {
        $tenant = $this->verifiedTenant();
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

    private function mqttDriver($driver): ManagesMqtt
    {
        return $driver instanceof ManagesMqtt ? $driver : throw UnsupportedOperation::for($driver->driverName(), 'mqtt');
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
            // Set when a collector (an xapi.pool check) discovered this device: a VM or a pool host. null for hosts the
            // customer created. A discovered host follows the collector, only tags, notes and external id are editable,
            // it does not count toward the host limit, is not billed, and disappears 7 days after the collector stops
            // reporting it (gone_at) or with the collector check.
            'discovered' => $h->raw['discovered'] ?? null,
        ];
    }

    /** `password` is only in the response that creates or rotates a credential: shown once, never logged. */
    private function mqttCredential(array $c): array
    {
        return [
            'id' => $c['id'] ?? null,
            'name' => $c['name'] ?? null,
            'username' => $c['username'] ?? null,
            'kind' => $c['kind'] ?? null,
            'device_key' => $c['device_key'] ?? null,
            'profile' => $c['profile'] ?? null,
            'allow_plain' => (bool) ($c['allow_plain'] ?? false),
            'auto_register' => (bool) ($c['auto_register'] ?? true),
            'enabled' => (bool) ($c['enabled'] ?? true),
            'created_at' => $c['created_at'] ?? null,
            'updated_at' => $c['updated_at'] ?? null,
            'last_used_at' => $c['last_used_at'] ?? null,
        ] + (isset($c['password']) ? ['password' => $c['password']] : []);
    }

    private function hostMqtt(array $m): array
    {
        return [
            'host_id' => $m['device_id'] ?? null,
            'device_key' => $m['device_key'] ?? null,
            'data_check_id' => $m['data_check_id'] ?? null,
            'connection_check_id' => $m['connection_check_id'] ?? null,
            'created_at' => $m['created_at'] ?? null,
            // the live broker session (node_id, client_id, remote, keepalive, connected_at); null when not connected
            'session' => $m['session'] ?? null,
        ];
    }

    private function check(Check $c): array
    {
        return $c->toArray() + [
            'timeout_seconds' => $c->raw['timeout_seconds'] ?? null,
            'failure_count' => $c->raw['failure_count'] ?? null,
            'recovery_count' => $c->raw['recovery_count'] ?? null,
            'runbook_url' => $c->raw['runbook_url'] ?? null,
            'unknown_is_critical' => $c->raw['unknown_is_critical'] ?? false,
            // role => credential id, e.g. {"auth": "..."}; the secrets themselves are never returned
            'credentials' => (object) ($c->raw['credentials'] ?? []),
            // push checks (plugin push.http): where devices send data (ingest_url, token_prefix, last_push_at); null for polled checks
            'push' => $c->raw['push'] ?? null,
        ] + (isset($c->raw['push_token']) ? [
            // the ingest token: only in the response that creates a push check or rotates its token, never again; never logged
            'push_token' => $c->raw['push_token'],
        ] : []);
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
            // collectors: the object (interface, outlet) this alert is about; null for a plain check
            'object_key' => $a->raw['object_key'] ?? null,
            'object_name' => $a->raw['object_name'] ?? null,
            // held back because an upstream host check explains it; not delivered while that root incident is open
            'suppressed' => (bool) ($a->raw['suppressed'] ?? false),
            'root_incident_id' => $a->raw['root_incident_id'] ?? null,
            'root_host_id' => $a->raw['root_device_id'] ?? null,
            'flapping' => (bool) ($a->raw['flapping'] ?? false),
            // what raised it: a threshold rule of the check, or "whoopsy" for Whoopsy! (rule_name "Whoopsy!")
            'rule_id' => $a->raw['rule_id'] ?? null,
            'rule_name' => $a->raw['rule_name'] ?? null,
        ];
    }

    private function site(Site $s): array
    {
        return ['id' => $s->id, 'name' => $s->name, 'country' => $s->country, 'timezone' => $s->timezone, 'address' => $s->address];
    }
}
