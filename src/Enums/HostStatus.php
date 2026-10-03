<?php

namespace NextDeveloper\Monitoring\Enums;

enum HostStatus: string
{
    case Up = 'up';
    case Down = 'down';
    case Disabled = 'disabled';
    case Unknown = 'unknown';
}
