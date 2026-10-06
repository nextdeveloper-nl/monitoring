<?php

namespace NextDeveloper\Monitoring\DataTransferObjects;

/**
 * A stored credential (SNMP community, SNMPv3 user, HTTP bearer token, ...). Secrets are write-only: the server never
 * returns them, only which secret fields are set ($secretsSet), so this DTO never holds a secret that came back from it.
 * On create or update, $fields may carry both the plain and the secret fields to send.
 */
final class Credential
{
    public function __construct(
        public readonly ?string $id,
        public readonly string $name,
        public readonly string $type,
        /** Non-secret fields when read back; all fields (secrets included) when sending. */
        public readonly array $fields = [],
        /** Names of the secret fields that have a value (read back only). */
        public readonly array $secretsSet = [],
        public readonly array $raw = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'fields' => $this->fields,
            'secrets_set' => $this->secretsSet,
        ];
    }
}
