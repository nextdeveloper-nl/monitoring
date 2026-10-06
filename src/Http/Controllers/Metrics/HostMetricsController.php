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
            // avg, min, max, sum; stddev; or a percentile such as p50, p95, p99 or p99.9 (the last two need raw data: about 7 days)
            'agg' => ['sometimes', 'string', 'regex:/^(avg|min|max|sum|stddev|p[0-9]{1,2}(\\.[0-9]{1,3})?)$/'],
            'moving_window' => 'sometimes|integer|min:1|max:1000',
            'check_id' => 'sometimes|string',
            'object' => 'sometimes|string|max:200',
        ]);

        return $this->respond(fn () => ['data' => $this->service->metrics($hostId, $params)]);
    }

    /** One set of statistics per series over the whole window: min, avg, stddev, p50, p95, p99, extra percentiles, last value. */
    public function summary(Request $request, string $hostId): JsonResponse
    {
        $params = $request->validate([
            'name' => 'sometimes|array|max:20',
            'name.*' => 'string|max:100',
            'from' => 'sometimes|date',
            'to' => 'sometimes|date|after:from',
            'check_id' => 'sometimes|string',
            'object' => 'sometimes|string|max:200',
            'percentile' => 'sometimes|array|max:10',
            'percentile.*' => ['string', 'regex:/^p[0-9]{1,2}(\\.[0-9]{1,3})?$/'],
            'window' => 'sometimes|integer|min:1|max:10000',
        ]);

        return $this->respond(fn () => ['data' => $this->service->metricSummary($hostId, $params)]);
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
