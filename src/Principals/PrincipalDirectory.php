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
     * Idempotent; reactivates a principal deactivated as `offboarded`, and
     * returns one deactivated for any other reason as it is (inactive).
     * Organisation ids are stored in lower case.
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
     *                                   a missing required organisation, an owner
     *                                   that is inactive or in another organisation,
     *                                   or an owner chain that is cyclic or too deep
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
     * The singleton instance-level principal of a declared instance type. It
     * is returned even when deactivated: check isActive() before use.
     *
     * @throws \InvalidArgumentException when the type is not in access.principals.instance_types
     * @throws \LogicException when the stored singleton has a user (schema rule missing)
     */
    public function instance(string $type, string $name): Principal;

    /**
     * Write an irreversible membership tombstone in the product's soft-delete
     * transaction. Keeps the user link so restoring or rejoining cannot
     * reactivate the principal, and retains the original deactivation time.
     * Deactivates owned principals and dispatches PrincipalDeactivatedEvent after
     * commit so the product can revoke their credentials.
     *
     * @throws \InvalidArgumentException when the stored principal is not type `user`
     *
     * @api
     */
    public function markErased(Principal $principal): void;

    /**
     * Sets deactivated_at and the reason unless the principal is already
     * deactivated (an erased tombstone stays erased), deactivates every active
     * owned principal (one transaction), then dispatches PrincipalDeactivatedEvent
     * after the surrounding transaction commits.
     */
    public function deactivate(Principal $principal, DeactivationReason $reason): void;

    /**
     * GDPR erasure of a user, in one transaction: every principal of the user
     * becomes an `erased` tombstone (user unlinked, name anonymised), the
     * principals it owns are deactivated, then $deleteUser deletes the user
     * row with a hard delete (forceDelete() on a soft-deleting model).
     * principals.user_id is ON DELETE RESTRICT, so this is the only way a user
     * with principals can be deleted. PrincipalDeactivatedEvent is dispatched per
     * erased principal after the commit.
     * The fleet eraser shares the principal scrub and lets the product's
     * orchestrator own the user deletion and completion event.
     *
     * @param  \Closure(Authenticatable): void  $deleteUser  the product's hard user deletion
     *
     * @throws \LogicException when the user row still exists afterwards (everything is rolled back)
     *
     * @deprecated 1.0.0 removed in 2.0.0: products delete users only through
     *             their erasure orchestrator, which runs Erasure\AccessEraser
     *             (fleet erasure decision 2026-11-24).
     */
    public function eraseUser(Authenticatable $user, \Closure $deleteUser): void;

    /**
     * Not deactivated, not expired, a `user` principal still has its user, and
     * every owner up the chain is active too. Never cached.
     */
    public function isActive(string $principalId): bool;

    /**
     * The principal followed by its owners up to the root, when isActive()
     * holds for it; null otherwise. Never cached.
     *
     * @return list<Principal>|null
     */
    public function activeOwnerChain(string $principalId): ?array;
}
