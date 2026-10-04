<?php

namespace NextDeveloper\Monitoring\DataTransferObjects;

final class Webhook
{
    public function __construct(
        public readonly ?string $id,
        public readonly string $name,
        public readonly string $url,
        public readonly bool $enabled = true,
        public readonly ?int $timeoutSeconds = null,
        /** Static extra headers, write-only: the server never returns values (see $raw['header_names']). null removes one. */
        public readonly array $headers = [],
        public readonly ?string $externalId = null,
        public readonly ?string $externalType = null,
        /** Signing secret (whsec_...). Only set on the response that created the webhook or rotated the secret; shown once. */
        public readonly ?string $secret = null,
        public readonly array $raw = [],
        /** Set when the server disabled the endpoint itself, e.g. after the receiver answered 410 Gone. */
        public readonly ?string $disabledReason = null,
    ) {
    }

    /** Never includes the secret. */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'enabled' => $this->enabled,
            'timeout_seconds' => $this->timeoutSeconds,
            'external_id' => $this->externalId,
            'disabled_reason' => $this->disabledReason,
        ];
    }
}
