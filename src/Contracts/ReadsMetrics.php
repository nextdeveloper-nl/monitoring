<?php

namespace NextDeveloper\Monitoring\Contracts;

use DateTimeInterface;
use Illuminate\Support\Collection;
use NextDeveloper\Monitoring\DataTransferObjects\MetricSeries;

interface ReadsMetrics
{
    /**
     * @param  string[]  $keys  empty = driver default set
     * @param  array  $options  driver hints, all optional: step (seconds), agg (avg|min|max|sum), resolution (raw|5m|1h), check_id, object
     * @return Collection<int, MetricSeries>
     */
    public function getMetrics(
        string $tenantId,
        string $hostId,
        array $keys = [],
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null,
        array $options = [],
    ): Collection;
}
