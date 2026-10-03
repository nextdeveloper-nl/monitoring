<?php

namespace NextDeveloper\Monitoring\Exceptions;

class DriverNotConfigured extends MonitoringException
{
    public static function unknown(string $driver): self
    {
        return new self("Monitoring driver [{$driver}] is not registered.");
    }

    public static function noServer(): self
    {
        return new self('No active monitoring server available. Pass one or flag a server as default.');
    }
}
