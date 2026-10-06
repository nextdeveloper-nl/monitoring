<?php

namespace NextDeveloper\Monitoring\Contracts;

/**
 * Optional capability ('whoopsy'): Whoopsy! premium alerting. An alert when a check's metric leaves its own band (the
 * moving average of the last N results, plus or minus some standard deviations, a number of times in a row).
 * While it is on, the check is billed at its plugin's weight times the service's whoopsy multiplier.
 */
interface ManagesWhoopsy
{
    /** @return array{check_id: string, enabled: bool, settings: ?array, band: ?array} */
    public function getWhoopsy(string $tenantId, string $checkId): array;

    /** Turns it on, or changes its settings; every setting is optional. Returns the status. */
    public function setWhoopsy(string $tenantId, string $checkId, array $settings): array;

    public function disableWhoopsy(string $tenantId, string $checkId): void;

    /** Accept a new normal: the band is learned again from the next results. 409 whoopsy-off when it is off. */
    public function resetWhoopsy(string $tenantId, string $checkId): array;

    /**
     * What Whoopsy! costs: the plugin weights and the multiplier from the service's usage configuration.
     *
     * @return array{multiplier: int, default_weight: int, weights: array<string, int>}
     */
    public function whoopsyPricing(string $tenantId): array;
}
