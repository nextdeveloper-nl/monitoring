<?php

namespace NextDeveloper\Monitoring\Contracts;

use DateTimeInterface;
use Illuminate\Support\Collection;

/** Optional capability ('usage'): billable usage per tenant per closed hour, read by billing. */
interface ReportsUsage
{
    /**
     * Platform-level read of closed hours. $from and $to must be whole UTC hours; open hours are refused by the server.
     *
     * @return Collection<int, array{account_id: string, period_start: string, period_end: string, billable_check_seconds: int, device_seconds: int, revision: int, breakdown: array}>
     */
    public function usage(DateTimeInterface $from, DateTimeInterface $to): Collection;
}
