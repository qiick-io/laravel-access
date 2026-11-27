<?php

declare(strict_types=1);

namespace Qiick\Access;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Qiick\Access\Contracts\RoleCatalog;

/**
 * Super-admin stands alone (decision 2026-11-26, Excubia #474): an instance
 * super-admin holds no other role, in any team, directly or through a live
 * group.
 *
 * write() is the one supported path for role and group writes (the product's
 * forms, OIDC role sync, API, its own commands): one transaction for every
 * affected user, rows locked, the resulting state checked, everything
 * rolled back when it breaks the rule. Writes
 * that skip it (`permission:assign-role`, a raw pivot attach) cannot leave a
 * hidden super-admin: the resolver withholds the bypass from a mixed holder
 * (fail-closed) and dispatches SuperAdminBypassWithheldEvent.
 *
 * @api
 */
final class ExclusiveSuperAdmin
{
    public function __construct(
        private readonly EffectivePermissionResolver $resolver,
        private readonly RoleCatalog $roleCatalog,
    ) {}

    /**
     * Whether a list of role names holds super-admin next to another role.
     * Names compare case-insensitively, as a case-insensitive role store does.
     *
     * @param  array<array-key, string>  $roleNames
     */
    public function breaks(array $roleNames): bool
    {
        $names = array_unique(array_map(mb_strtolower(...), $roleNames));

        return in_array(mb_strtolower($this->roleCatalog->superAdminRoleName()), $names, true) && count($names) > 1;
    }

    /**
     * Runs a role or group write atomically for every user it affects: a
     * user's roles, or a group's roles or members (pass every member before
     * and after the change). One transaction on the users' connection, each
     * user row locked in key order; afterwards each user must hold
     * super-admin alone or not at all, else everything rolls back.
     *
     * Pass the acting user for a write someone asked for: a write that makes
     * a user an effective super-admin who was not one before (a promotion,
     * or a mixed holder cleaned up) is refused unless the actor may manage
     * super-admins. Pass null only for trusted system writes (OIDC sync from
     * the issuer's claims, the product's own commands).
     *
     * @template TResult
     *
     * @param  (Model&Authenticatable)|iterable<Model&Authenticatable>  $users
     * @param  Closure(): TResult  $write
     * @return TResult
     *
     * @throws ExclusiveSuperAdminException after rolling the write back
     * @throws LogicException when the users live on different connections or a user row is missing
     */
    public function write(Model|iterable $users, Closure $write, ?Authenticatable $actor = null): mixed
    {
        $users = $users instanceof Model ? [$users] : array_values(is_array($users) ? $users : iterator_to_array($users, false));
        usort($users, fn (Model $first, Model $second): int => $first->getKey() <=> $second->getKey());
        $connection = $this->connectionOf($users);

        try {
            return $connection->transaction(function () use ($users, $write, $actor): mixed {
                $wasEffective = [];

                foreach ($users as $index => $user) {
                    // Serialises concurrent writes for the user, on the primary.
                    if ($user->newQueryWithoutScopes()->whereKey($user->getKey())->lockForUpdate()->first() === null) {
                        throw new LogicException('ExclusiveSuperAdmin::write() needs every user row to exist.');
                    }

                    $this->resolver->forget($user);
                    $wasEffective[$index] = $this->resolver->isSuperAdmin($user);
                }

                $result = $write();

                foreach ($users as $index => $user) {
                    $this->assertHeldAlone($user);
                    $this->resolver->forget($user);

                    if ($actor !== null && ! $wasEffective[$index] && $this->resolver->isSuperAdmin($user) && ! $this->guard()->canManageSuperAdmins($actor)) {
                        throw ExclusiveSuperAdminException::promotionBy($user);
                    }
                }

                return $result;
            });
        } finally {
            // Whatever the outcome, no verdict read inside the transaction survives it.
            foreach ($users as $user) {
                $this->resolver->forget($user);
                $user->unsetRelation('roles');
            }
        }
    }

    /**
     * @param  list<Model&Authenticatable>  $users
     */
    private function connectionOf(array $users): Connection
    {
        $names = array_unique(array_map(fn (Model $user): string => $user->getConnection()->getName() ?? '', $users));

        if (count($names) !== 1) {
            throw new LogicException('ExclusiveSuperAdmin::write() needs at least one user, all on one connection.');
        }

        return $users[0]->getConnection();
    }

    private function guard(): SuperAdminGuard
    {
        return Container::getInstance()->make(SuperAdminGuard::class);
    }

    /**
     * @throws ExclusiveSuperAdminException when the user holds super-admin with another role
     */
    public function assertHeldAlone(Authenticatable $user): void
    {
        if ($user instanceof Model) {
            $user->unsetRelation('roles');
        }

        if ($this->resolver->holdsMixedSuperAdmin($user)) {
            throw ExclusiveSuperAdminException::forUser($user);
        }
    }
}
