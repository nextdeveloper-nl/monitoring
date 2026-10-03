<?php

namespace NextDeveloper\Monitoring\Enums;

enum TenantStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
