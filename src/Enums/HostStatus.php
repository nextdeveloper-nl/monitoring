<?php

namespace NextDeveloper\Monitoring\Enums;

enum HostStatus: string
{
    case Up = 'up';
    case Down = 'down';
    case Disabled = 'disabled';
    // Check succeeds and no fixed threshold is breached, but the value is outside its Whoopsy! band.
    case Unusual = 'unusual';
    case Unknown = 'unknown';
}
