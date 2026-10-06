<?php

namespace NextDeveloper\Monitoring\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use NextDeveloper\Monitoring\Exceptions\ApiRequestFailed;
use NextDeveloper\Monitoring\Exceptions\MonitoringException;
use NextDeveloper\Monitoring\Exceptions\UnsupportedOperation;

/** Runs a service call and maps monitoring failures to the JSON error shape every monitoring endpoint uses. */
trait HandlesMonitoringResponses
{
    /**
     * Run a service call and map monitoring failures to JSON errors. Upstream status codes the client can act
     * on (404/409/422/403) pass through with the problem type and detail; anything else becomes 502.
     */
    /**
     * Boolean filters arrive as query strings: "true", "false", "1" or "0". Laravel's `boolean` rule rejects "true",
     * so filters use `in:true,false,1,0` and are converted here (a key that is absent stays absent).
     */
    protected function booleans(array $validated, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $validated)) {
                $validated[$key] = filter_var($validated[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $validated;
    }

    protected function respond(callable $call, int $status = 200): JsonResponse
    {
        try {
            $body = $call();

            return $status === 204 ? response()->json(null, 204) : response()->json($body, $status);
        } catch (ApiRequestFailed $e) {
            $passThrough = in_array($e->status, [400, 403, 404, 409, 422], true);
            $problem = is_array($e->body) ? $e->body : [];

            // Which upstream call failed (the message names method and path), its status, problem type and request id.
            // Passed-through errors (404, 409, ...) used to be invisible, which made them impossible to trace.
            Log::log($passThrough ? 'warning' : 'error', '[Monitoring] '.$e->getMessage(), [
                'status' => $e->status,
                'problem_type' => $problem['type'] ?? null,
                'request_id' => $problem['request_id'] ?? null,
                'detail' => $problem['detail'] ?? $problem['title'] ?? null,
            ]);

            return response()->json(['error' => [
                'type' => $passThrough ? basename((string) ($problem['type'] ?? 'monitoring-error')) : 'monitoring-unavailable',
                'message' => $passThrough ? ($problem['detail'] ?? $problem['title'] ?? 'Monitoring request failed.') : 'The monitoring service is unavailable.',
            ]], $passThrough ? $e->status : 502);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => ['type' => 'invalid-value', 'message' => $e->getMessage()]], 422);
        } catch (UnsupportedOperation $e) {
            return response()->json(['error' => ['type' => 'not-supported', 'message' => $e->getMessage()]], 501);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            // Another request is restoring this account's monitoring right now.
            return response()->json(['error' => ['type' => 'monitoring-preparing', 'message' => 'Monitoring is being prepared for this account. Try again in a moment.']], 503);
        } catch (MonitoringException $e) {
            // Includes "no monitoring server configured".
            Log::error('[Monitoring] '.get_class($e).': '.$e->getMessage());

            return response()->json(['error' => ['type' => 'monitoring-unavailable', 'message' => 'The monitoring service is not available.']], 503);
        }
    }
}
