<?php

namespace NextDeveloper\Monitoring\Http\Controllers;

use Illuminate\Routing\Controller;
use NextDeveloper\Monitoring\Http\Controllers\Concerns\HandlesMonitoringResponses;
use NextDeveloper\Monitoring\Services\MonitoringProxyService;

/** Base of the customer monitoring controllers: they validate and delegate to MonitoringProxyService. */
abstract class AbstractMonitoringController extends Controller
{
    use HandlesMonitoringResponses;

    public function __construct(protected readonly MonitoringProxyService $service) {}
}
