<?php

namespace NextDeveloper\Monitoring\Services;

use Illuminate\Support\Facades\Log;
use NextDeveloper\Monitoring\Contracts\ManagesHosts;
use NextDeveloper\Monitoring\Contracts\ManagesNotifications;
use NextDeveloper\Monitoring\Contracts\ManagesSites;
use NextDeveloper\Monitoring\Contracts\MonitoringDriver;
use NextDeveloper\Monitoring\Exceptions\ApiRequestFailed;

/**
 * Empties a tenant: deletes everything a customer configured, so a restored tenant starts clean.
 *
 * Restoring a deleted tenant on the monitoring service brings back everything it held (hosts, checks, webhooks,
 * routes, sites), and the checks run, and are billed, again. When the tenant was removed on purpose, that is not
 * wanted, so a tenant restored automatically is emptied right after. Deleting a host also deletes its checks and the
 * hosts inside it. This cannot be undone. Credentials and the history of past incidents are not touched.
 *
 * Runs with the platform key and no acting user, so it has full rights in the tenant.
 */
class TenantRecoveryService
{
    /**
     * @return array{routes: int, webhooks: int, hosts: int, sites: int} what was deleted
     */
    public function empty(MonitoringDriver $driver, string $externalId): array
    {
        $counts = ['routes' => 0, 'webhooks' => 0, 'hosts' => 0, 'sites' => 0];

        // Routes first (they point at webhooks), then webhooks.
        if ($driver instanceof ManagesNotifications) {
            foreach ($driver->listAlertRoutes($externalId) as $route) {
                $counts['routes'] += $this->delete(fn () => $driver->deleteAlertRoute($externalId, $route->id));
            }

            foreach ($driver->listWebhooks($externalId) as $webhook) {
                $counts['webhooks'] += $this->delete(fn () => $driver->deleteWebhook($externalId, $webhook->id));
            }
        }

        // Hosts take their checks and the hosts inside them with them; a child already gone is not an error.
        if ($driver instanceof ManagesHosts) {
            foreach ($driver->listHosts($externalId) as $host) {
                $counts['hosts'] += $this->delete(fn () => $driver->deleteHost($externalId, $host->id));
            }
        }

        // Sites last: a site with hosts cannot be deleted.
        if ($driver instanceof ManagesSites) {
            foreach ($driver->listSites($externalId) as $site) {
                $counts['sites'] += $this->delete(fn () => $driver->deleteSite($externalId, $site->id));
            }
        }

        Log::warning("[Monitoring] tenant {$externalId} was emptied after a restore", $counts);

        return $counts;
    }

    /** 1 when deleted, 0 when it was already gone. Any other failure stops the whole run so it can be retried. */
    private function delete(callable $call): int
    {
        try {
            $call();

            return 1;
        } catch (ApiRequestFailed $e) {
            if ($e->status === 404) {
                return 0;
            }

            throw $e;
        }
    }
}
