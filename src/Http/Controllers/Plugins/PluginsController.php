<?php

namespace NextDeveloper\Monitoring\Http\Controllers\Plugins;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NextDeveloper\Monitoring\Http\Controllers\AbstractMonitoringController;

class PluginsController extends AbstractMonitoringController
{
    public function index(): JsonResponse
    {
        return $this->respond(fn () => ['data' => $this->service->plugins()]);
    }
}
