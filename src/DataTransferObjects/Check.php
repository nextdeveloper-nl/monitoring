<?php

namespace NextDeveloper\Monitoring\DataTransferObjects;

final class Check
{
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $hostId,
        public readonly string $name,
        public readonly string $plugin,
        public readonly array $config = [],
        public readonly ?int $intervalSeconds = null,
        public readonly bool $enabled = true,
        public readonly array $thresholds = [],
        /** The check that says whether the host is up. */
        public readonly bool $isHostCheck = false,
        public readonly array $raw = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'host_id' => $this->hostId,
            'name' => $this->name,
            'plugin' => $this->plugin,
            'config' => $this->config,
            'interval_seconds' => $this->intervalSeconds,
            'enabled' => $this->enabled,
            'thresholds' => $this->thresholds,
            'is_host_check' => $this->isHostCheck,
        ];
    }
}
