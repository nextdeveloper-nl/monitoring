<?php

namespace NextDeveloper\Monitoring\Contracts;

use Illuminate\Support\Collection;

/** Optional capability ('plugins'): the check types the monitoring service offers, so clients need not hard-code them. */
interface ListsPlugins
{
    /**
     * @return Collection<int, array> manifests: type, description, kind, default_interval_seconds, min_interval_seconds,
     *                                config_schema (JSON Schema), metrics [{name, unit, kind, description}], credential_types
     */
    public function listPlugins(string $tenantId): Collection;
}
