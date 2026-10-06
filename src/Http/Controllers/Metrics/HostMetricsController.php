<?php

namespace NextDeveloper\Monitoring\Http\Controllers\Metrics;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NextDeveloper\Monitoring\Http\Controllers\AbstractMonitoringController;

class HostMetricsController extends AbstractMonitoringController
{
    public function index(Request $request, string $hostId): JsonResponse
    {
        $params = $request->validate([
            'name' => 'sometimes|array|max:20',
            'name.*' => 'string|max:100',
            'from' => 'sometimes|date',
            'to' => 'sometimes|date|after:from',
            'step' => 'sometimes|integer|min:10|max:2592000',
            'agg' => 'sometimes|string|in:avg,min,max,sum',
            'check_id' => 'sometimes|string',
            'object' => 'sometimes|string|max:200',
        ]);

        return $this->respond(fn () => ['data' => $this->service->metrics($hostId, $params)]);
    }

    public function series(Request $request, string $hostId): JsonResponse
    {
        $params = $request->validate([
            'name' => 'sometimes|array|max:20',
            'name.*' => 'string|max:100',
            'check_id' => 'sometimes|string',
            'object' => 'sometimes|string|max:500',
        ]);

        return $this->respond(fn () => ['data' => $this->service->metricSeries($hostId, $params)]);
    }
}
