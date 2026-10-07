<?php

namespace NextDeveloper\Monitoring\Contracts;

use Illuminate\Support\Collection;

/**
 * Optional capability ('mqtt'): MQTT devices send data to the monitoring service's embedded broker with a tenant
 * MQTT credential. Items are the service's own arrays (MqttCredential, DeviceMqtt); a password is only in the
 * response that creates or rotates a credential, shown once.
 */
interface ManagesMqtt
{
    /** @return Collection<int, array> */
    public function listMqttCredentials(string $tenantId): Collection;

    public function getMqttCredential(string $tenantId, string $credentialId): array;

    /** Body: name, kind (device|shared), username?, password?, device_key?, profile?, allow_plain?, auto_register?, enabled?. */
    public function createMqttCredential(string $tenantId, array $body): array;

    /** Body: name, allow_plain, auto_register, enabled (all optional). */
    public function updateMqttCredential(string $tenantId, string $credentialId, array $body): array;

    public function deleteMqttCredential(string $tenantId, string $credentialId): void;

    /** New password (generated when $password is null), shown once in the response. */
    public function rotateMqttCredential(string $tenantId, string $credentialId, ?string $password = null): array;

    /** @return Collection<int, array> name, description of the message profiles (fixlean-esp, json). */
    public function listMqttProfiles(string $tenantId): Collection;

    /** @return Collection<int, array> device_key, credential_id, reason, messages, first_seen, last_seen of dropped messages. */
    public function listMqttUnregistered(string $tenantId): Collection;

    /** Pre-registers a device key on a host (409 already-bound). */
    public function bindHostMqtt(string $tenantId, string $hostId, array $body): array;

    /** 404 when the host is not an MQTT device. */
    public function getHostMqtt(string $tenantId, string $hostId): array;

    /** Also removes the device's two checks. */
    public function unbindHostMqtt(string $tenantId, string $hostId): void;
}
