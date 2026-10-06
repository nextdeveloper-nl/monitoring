<?php

namespace NextDeveloper\Monitoring\Contracts;

use Illuminate\Support\Collection;

/** Optional capability ('metrics'): discover which metric series are stored for a host or check. */
interface ListsMetricSeries
{
    /** @return Collection<int, array> series descriptors: id, plugin, object, name, unit, kind, retention_class */
    public function listMetricSeries(string $tenantId, ?string $hostId = null, ?string $checkId = null, array $keys = [], ?string $object = null): Collection;
}
