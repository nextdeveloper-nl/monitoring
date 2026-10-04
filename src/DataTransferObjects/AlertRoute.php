<?php

namespace NextDeveloper\Monitoring\DataTransferObjects;

final class AlertRoute
{
    public function __construct(
        public readonly ?string $id,
        public readonly string $name,
        /** The webhook this route delivers to. */
        public readonly string $webhookId,
        public readonly ?int $position = null,
        public readonly bool $enabled = true,
        /** severity[], device_types[], site_ids[], tags{}, event_types[]; empty matches everything. */
        public readonly array $match = [],
        /** Keep evaluating later routes after this one matched. */
        public readonly bool $continue = false,
        /** Sent to the receiver as data.route.labels (recipient, channel, ...). */
        public readonly array $labels = [],
        public readonly ?string $externalId = null,
        public readonly ?string $externalType = null,
        public readonly array $raw = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'webhook_id' => $this->webhookId,
            'position' => $this->position,
            'enabled' => $this->enabled,
            'match' => $this->match,
            'continue' => $this->continue,
            'labels' => $this->labels,
            'external_id' => $this->externalId,
        ];
    }
}
