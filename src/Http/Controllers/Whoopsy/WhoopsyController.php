<?php

namespace NextDeveloper\Monitoring\Http\Controllers\Whoopsy;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NextDeveloper\Monitoring\Http\Controllers\AbstractMonitoringController;

/**
 * Whoopsy! premium alerting on one check. Turning it on raises the check's price, so it needs an explicit confirmation.
 */
class WhoopsyController extends AbstractMonitoringController
{
    public function show(string $checkId): JsonResponse
    {
        return $this->respond(fn () => ['data' => $this->service->whoopsy($checkId)]);
    }

    /** Turn it on (needs confirm_price: true) or change its settings. */
    public function update(Request $request, string $checkId): JsonResponse
    {
        $data = $request->validate([
            'metric' => 'sometimes|string|max:100',
            'window' => 'sometimes|integer|min:3|max:1000',
            'deviations' => 'sometimes|numeric|gt:0|max:10',
            'consecutive' => 'sometimes|integer|min:1|max:100',
            'direction' => 'sometimes|string|in:above,below,both',
            'min_delta' => 'sometimes|numeric|min:0',
            'severity' => 'sometimes|string|in:warning,critical',
            // the customer has seen that Whoopsy! raises the check's price; only needed when it is currently off
            'confirm_price' => 'sometimes|boolean',
        ]);

        $confirm = (bool) ($data['confirm_price'] ?? false);
        unset($data['confirm_price']);

        return $this->respond(fn () => ['data' => $this->service->setWhoopsy($checkId, $data, $confirm)]);
    }

    /** The band Whoopsy! used for each result in the window, to draw on a graph. */
    public function band(Request $request, string $checkId): JsonResponse
    {
        $params = $request->validate(['from' => 'sometimes|date', 'to' => 'sometimes|date|after:from']);

        return $this->respond(fn () => ['data' => $this->service->whoopsyBand($checkId, $params['from'] ?? null, $params['to'] ?? null)]);
    }

    public function destroy(string $checkId): JsonResponse
    {
        return $this->respond(function () use ($checkId) {
            $this->service->disableWhoopsy($checkId);

            return [];
        }, 204);
    }

    /** Accept a new normal: the band is learned again; an open Whoopsy! alert resolves with the next result. */
    public function reset(string $checkId): JsonResponse
    {
        return $this->respond(fn () => ['data' => $this->service->resetWhoopsy($checkId)]);
    }
}
