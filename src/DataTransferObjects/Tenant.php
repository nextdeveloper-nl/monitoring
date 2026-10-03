<?php

namespace NextDeveloper\Monitoring\DataTransferObjects;

use NextDeveloper\Monitoring\Enums\TenantStatus;

final class Tenant
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly TenantStatus $status = TenantStatus::Active,
        public readonly array $raw = [],
    ) {
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'status' => $this->status->value];
    }
}
