<?php

namespace NextDeveloper\Monitoring\Contracts;

/**
 * Optional capability ('members'): who may act in a tenant, and as what. A driver that supports it also lets callers
 * act as one user (`actingAs()`), so the monitoring service applies that user's role and audits their name.
 */
interface ManagesMembers
{
    /** Role names the monitoring service knows for members. */
    public const ROLE_READ_ONLY = 'read-only';

    public const ROLE_OPERATOR = 'operator';

    /** Add a user to the tenant or change their role. Idempotent. $role is read-only or operator (admin is the service owner's). */
    public function upsertMember(string $tenantId, string $userExternalId, string $role): void;

    public function removeMember(string $tenantId, string $userExternalId): void;

    /** A copy of this driver whose tenant calls act as the given user. Pass null to act as the platform again. */
    public function actingAs(?string $userExternalId): static;
}
