<?php

namespace NextDeveloper\Monitoring\Enums;

enum TenantStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    /** Only ever read from the monitoring service (a soft-deleted tenant); never stored locally. */
    case Deleted = 'deleted';
}
