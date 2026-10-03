<?php

namespace NextDeveloper\Monitoring\DataTransferObjects;

use DateTimeInterface;
use NextDeveloper\Monitoring\Enums\AlertSeverity;
use NextDeveloper\Monitoring\Enums\AlertStatus;

final class Alert
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly AlertSeverity $severity,
        public readonly AlertStatus $status,
        public readonly ?string $hostId = null,
        public readonly ?DateTimeInterface $startedAt = null,
        public readonly array $raw = [],
    ) {
    }
}
