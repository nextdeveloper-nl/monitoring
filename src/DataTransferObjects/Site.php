<?php

namespace NextDeveloper\Monitoring\DataTransferObjects;

final class Site
{
    public function __construct(
        public readonly ?string $id,
        public readonly string $name,
        public readonly ?string $country = null,
        public readonly ?string $timezone = null,
        public readonly ?string $address = null,
        /** The source object's UUID and full model class; the stable key for idempotent upserts. */
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
            'country' => $this->country,
            'timezone' => $this->timezone,
            'address' => $this->address,
            'external_id' => $this->externalId,
        ];
    }
}
