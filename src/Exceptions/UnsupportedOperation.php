<?php

namespace NextDeveloper\Monitoring\Exceptions;

class UnsupportedOperation extends MonitoringException
{
    public static function for(string $driver, string $operation): self
    {
        return new self("Driver [{$driver}] does not support [{$operation}].");
    }
}
