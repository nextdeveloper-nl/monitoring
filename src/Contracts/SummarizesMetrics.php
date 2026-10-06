<?php

namespace NextDeveloper\Monitoring\Contracts;

use DateTimeInterface;
use Illuminate\Support\Collection;

/** Optional capability ('summary'): one set of statistics per metric series over a whole window (min, avg, percentiles, ...). */
interface SummarizesMetrics
{
    /**
     * @param  string[]  $keys  metric names
     * @param  array  $options  check_id, object, percentile (string[] such as p90, p99.9), window (last N samples)
     * @return array{resolution: ?string, exact: bool, from: ?string, to: ?string, series: Collection<int, array>}
     */
    public function summarizeMetrics(string $tenantId, string $hostId, array $keys = [], ?DateTimeInterface $from = null, ?DateTimeInterface $to = null, array $options = []): array;
}
