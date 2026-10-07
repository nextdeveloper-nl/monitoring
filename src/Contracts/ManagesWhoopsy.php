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
     * The band the engine actually used for each result while Whoopsy! was on (recorded at evaluation time, under the
     * settings in force then). Default window: the last hour; at most 7 days and 20,000 points, else 422 on `from`.
     *
     * @return array{check_id: string, from: ?string, to: ?string, points: array<int, array>} points: t, value, mean, stddev, lower, upper, outside, hits, alerting
     */
    public function whoopsyBand(string $tenantId, string $checkId, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array;

    /**
     * What Whoopsy! costs: the plugin weights and the multiplier from the service's usage configuration.
     *
     * @return array{multiplier: int, default_weight: int, weights: array<string, int>}
     */
    public function whoopsyPricing(string $tenantId): array;
}
