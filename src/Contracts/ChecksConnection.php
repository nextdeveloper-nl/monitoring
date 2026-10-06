<?php

namespace NextDeveloper\Monitoring\Contracts;

/** Optional capability ('connection'): verify that the server is reachable and that our platform credentials are accepted. */
interface ChecksConnection
{
    /** Throws ApiRequestFailed when the server cannot be reached or rejects the credentials. */
    public function checkConnection(): void;
}
