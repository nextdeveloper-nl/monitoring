<?php

namespace NextDeveloper\Monitoring\Contracts;

use DateTimeInterface;
use Illuminate\Support\Collection;
use NextDeveloper\Monitoring\DataTransferObjects\MetricSeries;

interface ReadsMetrics
{
    /**
     * @param  string[]  $keys  empty = driver default set
     * @return Collection<int, MetricSeries>
     */
    public function getMetrics(
        string $tenantId,
        string $hostId,
        array $keys = [],
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null,
    ): Collection;
}
