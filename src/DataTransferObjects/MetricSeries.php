<?php

namespace NextDeveloper\Monitoring\DataTransferObjects;

use Illuminate\Support\Collection;

final class MetricSeries
{
    /** @param Collection<int, Metric> $points */
    public function __construct(
        public readonly string $key,
        public readonly Collection $points,
        public readonly ?string $unit = null,
    ) {
    }
}
