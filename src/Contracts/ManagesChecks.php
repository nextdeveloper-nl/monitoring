<?php

namespace NextDeveloper\Monitoring\Contracts;

use Illuminate\Support\Collection;
use NextDeveloper\Monitoring\DataTransferObjects\Check;
use NextDeveloper\Monitoring\DataTransferObjects\CheckResult;
use NextDeveloper\Monitoring\DataTransferObjects\CheckState;

/** Optional capability ('checks'): drivers opt in; test with `$driver instanceof ManagesChecks` or supports('checks'). */
interface ManagesChecks
{
    /** @return Collection<int, Check> all checks of the tenant, or only those of one host. */
    public function listChecks(string $tenantId, ?string $hostId = null, array $filters = []): Collection;

    public function getCheck(string $tenantId, string $checkId): Check;

    /** $check->hostId is required. */
    public function createCheck(string $tenantId, Check $check): Check;

    /** Partial update; omitted attributes stay as they are. */
    public function updateCheck(string $tenantId, string $checkId, array $attributes): Check;

    public function deleteCheck(string $tenantId, string $checkId): void;

    /** Current state; null before the first result. */
    public function getCheckState(string $tenantId, string $checkId): ?CheckState;

    /** Ask the engine to run the check now (asynchronous). */
    public function runCheckNow(string $tenantId, string $checkId): void;

    /** Run every enabled check of the host once, synchronously. @return Collection<int, CheckResult> */
    public function testHost(string $tenantId, string $hostId): Collection;
}
