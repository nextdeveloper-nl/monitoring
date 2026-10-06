<?php

namespace NextDeveloper\Monitoring\Http\Controllers\Servers;

use Illuminate\Routing\Controller;
use NextDeveloper\Monitoring\Services\MonitoringServerService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use NextDeveloper\IAM\Helpers\UserHelper;

/**
 * Platform administration of monitoring servers (the connection to a monitoring service instance).
 * Only monitoring administrators (config monitoring.admin_roles); customers never reach this. Credentials are write-only and never returned.
 */
class ServersController extends Controller
{
    public function __construct(private readonly MonitoringServerService $service)
    {
        // Before validation, so a non-admin learns nothing about the request shape.
        $this->middleware(function (Request $request, \Closure $next) {
            if (! $this->isAdmin()) {
                return response()->json(['error' => ['type' => 'forbidden', 'message' => 'Only monitoring administrators can manage monitoring servers.']], 403);
            }

            return $next($request);
        });
    }

    /** Any of monitoring.admin_roles (monitoring-admin, system-admin). */
    private function isAdmin(): bool
    {
        foreach (config('monitoring.admin_roles', []) as $role) {
            if (UserHelper::hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    public function index(): JsonResponse
    {
        return $this->run(fn () => ['data' => $this->service->list()]);
    }

    public function show(string $serverId): JsonResponse
    {
        return $this->run(fn () => ['data' => $this->service->get($serverId)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'driver' => 'required|string|max:64',
            'base_url' => 'required|url|max:512',
            'credentials' => 'required|array',
            'credentials.token' => 'required|string|min:8|max:500',
            'options' => 'sometimes|nullable|array',
            'is_default' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        return $this->run(fn () => ['data' => $this->service->create($data)], 201);
    }

    public function update(Request $request, string $serverId): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'driver' => 'sometimes|string|max:64',
            'base_url' => 'sometimes|url|max:512',
            'credentials' => 'sometimes|array',
            'credentials.token' => 'required_with:credentials|string|min:8|max:500',
            'options' => 'sometimes|nullable|array',
            'is_default' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        return $this->run(fn () => ['data' => $this->service->update($serverId, $data)]);
    }

    public function destroy(string $serverId): JsonResponse
    {
        return $this->run(function () use ($serverId) {
            $this->service->delete($serverId);

            return [];
        }, 204);
    }

    /** Tries the stored credentials against the server. A failed connection is a normal answer (ok=false), not an error. */
    public function test(string $serverId): JsonResponse
    {
        return $this->run(fn () => ['data' => $this->service->test($serverId)]);
    }

    private function run(callable $call, int $status = 200): JsonResponse
    {
        try {
            $body = $call();

            return $status === 204 ? response()->json(null, 204) : response()->json($body, $status);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => ['type' => 'not-found', 'message' => 'Not found']], 404);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => ['type' => 'invalid-value', 'message' => $e->getMessage()]], 422);
        } catch (\DomainException $e) {
            return response()->json(['error' => ['type' => 'conflict', 'message' => $e->getMessage()]], 409);
        } catch (\Throwable $e) {
            Log::error('[MonitoringServersController] '.get_class($e).': '.$e->getMessage());

            return response()->json(['error' => ['type' => 'server-error', 'message' => 'The request could not be completed.']], 500);
        }
    }
}
