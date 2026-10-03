<?php

namespace NextDeveloper\Monitoring\DataTransferObjects;

use DateTimeInterface;
use NextDeveloper\Monitoring\Enums\AlertSeverity;

final class Event
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $hostId = null,
        public readonly AlertSeverity $severity = AlertSeverity::Info,
        public readonly ?string $message = null,
        public readonly ?DateTimeInterface $timestamp = null,
        public readonly array $tags = [],
    ) {
    }
}
