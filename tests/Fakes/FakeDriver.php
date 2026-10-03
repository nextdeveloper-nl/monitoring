<?php

namespace NextDeveloper\Monitoring\Tests\Fakes;

use NextDeveloper\Monitoring\DataTransferObjects\Tenant;
use NextDeveloper\Monitoring\Drivers\NullDriver;

/** Records tenant calls; reusable by consumers testing against the library. */
class FakeDriver extends NullDriver
{
    public static array $created = [];

    public static array $deleted = [];

    public static bool $failDelete = false;

    public function driverName(): string
    {
        return 'fake';
    }

    public function createTenant(string $name, array $options = []): Tenant
    {
        $tenant = new Tenant('remote-'.(count(self::$created) + 1), $name);
        self::$created[] = $tenant->id;

        return $tenant;
    }

    public function deleteTenant(string $tenantId): void
    {
        if (self::$failDelete) {
            throw new \RuntimeException('delete failed');
        }

        self::$deleted[] = $tenantId;
    }

    public static function reset(): void
    {
        self::$created = self::$deleted = [];
        self::$failDelete = false;
    }
}
