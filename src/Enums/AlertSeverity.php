<?php

namespace NextDeveloper\Monitoring\Enums;

enum AlertSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Average = 'average';
    case High = 'high';
    case Critical = 'critical';
}
