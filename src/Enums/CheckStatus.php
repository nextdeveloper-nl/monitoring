<?php

namespace NextDeveloper\Monitoring\Enums;

enum CheckStatus: string
{
    case Ok = 'OK';
    case Warning = 'WARNING';
    case Critical = 'CRITICAL';
    case Unknown = 'UNKNOWN';

    public static function fromServer(?string $value): self
    {
        return self::tryFrom(strtoupper((string) $value)) ?? self::Unknown;
    }
}
