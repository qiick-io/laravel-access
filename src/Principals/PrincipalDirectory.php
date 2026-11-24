<?php

declare(strict_types=1);

namespace Qiick\Access\Principals;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Creates, finds and deactivates principals (design §15.4). Products call it
 * from their own membership, service-account and offboarding flows; it never
 * reacts to product events itself.
 *
 * @api
 */
interface PrincipalDirectory
{
    /**
     * The user's principal in an organisation (null: instance level).
     * Idempotent; reactivates a deactivated membership principal.
     *
     * @throws \InvalidArgumentException when organisations are required and none is given
     * @throws \LogicException when the principal was erased (a GDPR tombstone)
     */
    public function forMember(Authenticatable $user, ?string $organisationId): Principal;

    /**
     * A service or agent principal, optionally owned by another principal.
     *
     * @param  array<string, mixed>  $metadata
     *
     * @throws \InvalidArgumentException on an unknown, membership or instance type,
     *                                   a missing required organisation, or an owner
     *                                   chain that is cyclic or too deep
     */
    public function createService(
        string $type,
        ?string $organisationId,
        string $name,
        ?Principal $owner = null,
        ?Authenticatable $createdBy = null,
        array $metadata = [],
    ): Principal;

    /**
     * The singleton instance-level principal of a declared instance type.
     *
     * @throws \InvalidArgumentException when the type is not in access.principals.instance_types
     */
    public function instance(string $type, string $name): Principal;

    /**
     * Sets deactivated_at and the reason, then deactivates every owned
     * principal (one transaction), then dispatches PrincipalDeactivated.
     */
    public function deactivate(Principal $principal, DeactivationReason $reason): void;

    /**
     * Not deactivated, not expired, a `user` principal still has its user, and
     * every owner up the chain is active too. Never cached.
     */
    public function isActive(string $principalId): bool;
}
