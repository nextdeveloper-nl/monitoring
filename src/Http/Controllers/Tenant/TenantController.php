<?php

namespace NextDeveloper\Monitoring\Http\Controllers\Tenant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NextDeveloper\Monitoring\Http\Controllers\AbstractMonitoringController;

class TenantController extends AbstractMonitoringController
{
    public function show(): JsonResponse
    {
        return $this->respond(fn () => ['data' => $this->service->tenantInfo()]);
    }
}
