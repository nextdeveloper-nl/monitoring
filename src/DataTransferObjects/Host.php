<?php

namespace NextDeveloper\Monitoring\DataTransferObjects;

use NextDeveloper\Monitoring\Enums\HostStatus;

final class Host
{
    public function __construct(
        public readonly ?string $id,
        public readonly string $name,
        public readonly ?string $address = null,
        public readonly HostStatus $status = HostStatus::Unknown,
        public readonly array $tags = [],
        public readonly array $raw = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            'status' => $this->status->value,
            'tags' => $this->tags,
        ];
    }
}
