<?php

namespace NextDeveloper\Monitoring\Http\Controllers\Sites;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NextDeveloper\Monitoring\Http\Controllers\AbstractMonitoringController;

class SitesController extends AbstractMonitoringController
{
    public function index(): JsonResponse
    {
        return $this->respond(fn () => ['data' => $this->service->listSites()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:200',
            'country' => 'sometimes|nullable|string|size:2|regex:/^[A-Z]{2}$/',
            'timezone' => 'sometimes|nullable|timezone',
            'address' => 'sometimes|nullable|string|max:1000',
        ]);

        return $this->respond(fn () => ['data' => $this->service->createSite($data)], 201);
    }

    public function destroy(string $siteId): JsonResponse
    {
        return $this->respond(function () use ($siteId) {
            $this->service->deleteSite($siteId);

            return [];
        }, 204);
    }
}
