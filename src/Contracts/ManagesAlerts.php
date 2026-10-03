<?php

namespace NextDeveloper\Monitoring\Contracts;

use Illuminate\Support\Collection;
use NextDeveloper\Monitoring\DataTransferObjects\Alert;

interface ManagesAlerts
{
    /** @return Collection<int, Alert> */
    public function listAlerts(string $tenantId, array $filters = []): Collection;

    public function acknowledgeAlert(string $tenantId, string $alertId, ?string $note = null): Alert;

    public function resolveAlert(string $tenantId, string $alertId): Alert;
}
