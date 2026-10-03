<?php

namespace NextDeveloper\Monitoring\Exceptions;

use Throwable;

class ApiRequestFailed extends MonitoringException
{
    public function __construct(string $message, public readonly ?int $status = null, public readonly mixed $body = null, ?Throwable $previous = null)
    {
        parent::__construct($message, $status ?? 0, $previous);
    }
}
