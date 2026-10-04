<?php

namespace NextDeveloper\Monitoring\DataTransferObjects;

use NextDeveloper\Monitoring\Enums\CheckStatus;

/** One synchronous test run of a check; nothing is stored on the server. */
final class CheckResult
{
    public function __construct(
        public readonly string $checkId,
        public readonly string $name,
        public readonly string $plugin,
        public readonly CheckStatus $status,
        public readonly ?string $output = null,
        public readonly ?int $durationMs = null,
        public readonly array $metrics = [],
        public readonly array $raw = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'check_id' => $this->checkId,
            'name' => $this->name,
            'plugin' => $this->plugin,
            'status' => $this->status->value,
            'output' => $this->output,
            'duration_ms' => $this->durationMs,
            'metrics' => $this->metrics,
        ];
    }
}
