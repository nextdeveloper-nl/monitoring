<?php

namespace NextDeveloper\Monitoring\Drivers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use NextDeveloper\Monitoring\Contracts\MonitoringDriver;
use NextDeveloper\Monitoring\Exceptions\ApiRequestFailed;
use NextDeveloper\Monitoring\Exceptions\UnsupportedOperation;
use NextDeveloper\Monitoring\Models\MonitoringServer;

/**
 * Base for HTTP based drivers. Nothing is stored locally: every call goes to the monitoring server.
 */
abstract class AbstractDriver implements MonitoringDriver
{
    /** Capabilities this driver implements. Override in subclass. */
    protected array $capabilities = ['tenants', 'hosts', 'metrics', 'alerts', 'push'];

    public function __construct(protected readonly MonitoringServer $server)
    {
    }

    public function server(): MonitoringServer
    {
        return $this->server;
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    /** Apply driver specific authentication (token header, login session, ...). */
    protected function authenticate(PendingRequest $request): PendingRequest
    {
        $token = $this->server->credential('token');

        return $token ? $request->withToken($token) : $request;
    }

    protected function http(): PendingRequest
    {
        $request = Http::baseUrl(rtrim($this->server->base_url, '/'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('monitoring.http.timeout', 15))
            ->retry(
                (int) config('monitoring.http.retries', 2),
                (int) config('monitoring.http.retry_delay_ms', 200),
                fn ($e) => $e instanceof ConnectionException,
                throw: false,
            );

        return $this->authenticate($request);
    }

    /** Send a request; returns decoded JSON body. Throws ApiRequestFailed on any failure. */
    protected function request(string $method, string $path, array $data = [], array $query = []): array
    {
        try {
            $response = $this->http()->send($method, ltrim($path, '/'), array_filter([
                'query' => $query ?: null,
                'json' => $data ?: null,
            ]));
        } catch (ConnectionException $e) {
            Log::error('['.static::class."] connection failed: {$method} {$path}", ['error' => $e->getMessage()]);

            throw new ApiRequestFailed("Cannot reach monitoring server [{$this->server->name}].", null, null, $e);
        }

        if ($response->failed()) {
            Log::warning('['.static::class."] {$method} {$path} -> {$response->status()}");

            throw new ApiRequestFailed(
                "Monitoring server [{$this->server->name}] returned {$response->status()} for {$method} {$path}.",
                $response->status(),
                $response->json() ?? $response->body(),
                $response->toException() instanceof RequestException ? $response->toException() : null,
            );
        }

        return $response->json() ?? [];
    }

    protected function unsupported(string $operation): never
    {
        throw UnsupportedOperation::for($this->driverName(), $operation);
    }
}
