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
        /** Fields incidents are grouped by (root_device_id, device_id, site_id, severity, check_id, plugin, device_type). Empty: no grouping. */
        public readonly array $groupBy = [],
        /** Seconds to collect a group before sending it (0 to 600). */
        public readonly ?int $groupWaitSeconds = null,
        /** Resend open, unacknowledged incidents every this many seconds (300 to 604800); null: never. */
        public readonly ?int $repeatIntervalSeconds = null,
        /** Escalation steps: [{after_seconds, labels?, only_if_unacknowledged?, schedule?}]. */
        public readonly array $steps = [],
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
            'group_by' => $this->groupBy,
            'group_wait_seconds' => $this->groupWaitSeconds,
            'repeat_interval_seconds' => $this->repeatIntervalSeconds,
            'steps' => $this->steps,
        ];
    }
}
