<?php

namespace NextDeveloper\Monitoring\Contracts;

use Illuminate\Support\Collection;
use NextDeveloper\Monitoring\DataTransferObjects\Site;

/** Optional capability ('sites'). */
interface ManagesSites
{
    /** @return Collection<int, Site> */
    public function listSites(string $tenantId): Collection;

    /** Idempotent: $site->externalId is the key; a retry returns the same site. */
    public function upsertSite(string $tenantId, Site $site): Site;

    /** Fails (409) while devices are still in the site. */
    public function deleteSite(string $tenantId, string $siteId): void;
}
