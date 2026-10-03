<?php

namespace NextDeveloper\Monitoring\DataTransferObjects;

use DateTimeInterface;

final class Metric
{
    public function __construct(
        public readonly string $key,
        public readonly int|float|string $value,
        public readonly ?DateTimeInterface $timestamp = null,
        public readonly array $tags = [],
    ) {
    }
}
