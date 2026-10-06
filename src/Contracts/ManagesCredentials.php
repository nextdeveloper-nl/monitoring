<?php

namespace NextDeveloper\Monitoring\Contracts;

use Illuminate\Support\Collection;
use NextDeveloper\Monitoring\DataTransferObjects\Credential;

/** Optional capability ('credentials'): the credentials checks use to log in to a device (SNMP, HTTP auth, ...). Secrets are write-only. */
interface ManagesCredentials
{
    /**
     * The kinds of credential and their fields. @return Collection<int, array> name, description, fields_schema (secret fields are writeOnly)
     */
    public function listCredentialTypes(string $tenantId): Collection;

    /** @return Collection<int, Credential> */
    public function listCredentials(string $tenantId): Collection;

    public function getCredential(string $tenantId, string $credentialId): Credential;

    public function createCredential(string $tenantId, Credential $credential): Credential;

    /** Replaces the credential; a secret field left out keeps its stored value. */
    public function updateCredential(string $tenantId, string $credentialId, Credential $credential): Credential;

    /** Refused (409 in-use) while a check still uses it. */
    public function deleteCredential(string $tenantId, string $credentialId): void;
}
