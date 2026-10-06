<?php

namespace NextDeveloper\Monitoring\Contracts;

use Illuminate\Support\Collection;

/** Optional capability ('collectors'): the objects a collector check reports (interfaces of a switch, outlets of a PDU, ...), each with its own state. */
interface ListsCheckObjects
{
    /**
     * @param  string|null  $status  OK|WARNING|CRITICAL|UNKNOWN
     * @param  bool  $includeGone  objects the device stopped reporting are kept for 30 days
     * @return Collection<int, array> key, name, labels, phase, status, since, last_status, last_output, last_metrics, incident_id, first_seen_at, last_seen_at, gone_at
     */
    public function listCheckObjects(string $tenantId, string $checkId, ?string $status = null, bool $includeGone = true): Collection;
}
