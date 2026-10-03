<?php

namespace NextDeveloper\Monitoring\Contracts;

use NextDeveloper\Monitoring\DataTransferObjects\Event;
use NextDeveloper\Monitoring\DataTransferObjects\Metric;

interface PushesData
{
    /** @param iterable<Metric> $metrics */
    public function pushMetrics(string $tenantId, string $hostId, iterable $metrics): void;

    public function pushEvent(string $tenantId, Event $event): void;
}
