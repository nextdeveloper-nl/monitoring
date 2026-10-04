<?php

namespace NextDeveloper\Monitoring\DataTransferObjects;

use DateTimeImmutable;
use NextDeveloper\Monitoring\Enums\CheckStatus;

final class CheckState
{
    public function __construct(
        public readonly string $checkId,
        /** OK | PENDING | PROBLEM */
        public readonly string $phase,
        public readonly CheckStatus $status,
        public readonly ?string $output = null,
        public readonly array $metrics = [],
        public readonly ?DateTimeImmutable $since = null,
        public readonly ?DateTimeImmutable $lastResultAt = null,
        public readonly ?string $incidentId = null,
        public readonly array $raw = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'check_id' => $this->checkId,
            'phase' => $this->phase,
            'status' => $this->status->value,
            'output' => $this->output,
            'metrics' => $this->metrics,
            'since' => $this->since?->format(DATE_ATOM),
            'last_result_at' => $this->lastResultAt?->format(DATE_ATOM),
            'incident_id' => $this->incidentId,
        ];
    }
}
