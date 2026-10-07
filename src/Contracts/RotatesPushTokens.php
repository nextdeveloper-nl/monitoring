<?php

namespace NextDeveloper\Monitoring\Contracts;

use NextDeveloper\Monitoring\DataTransferObjects\Check;

/** Optional capability: push checks (kind "ingester") receive data from devices with a per-check token. */
interface RotatesPushTokens
{
    /** Issues a new push token; the old one stops working at once. The new token is in $check->raw['push_token'], shown only here. */
    public function rotateCheckToken(string $tenantId, string $checkId): Check;
}
