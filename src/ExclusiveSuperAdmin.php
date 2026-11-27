<?php

declare(strict_types=1);

namespace Qiick\Access;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Qiick\Access\Contracts\RoleCatalog;

/**
 * Super-admin stands alone (decision 2026-11-26, Excubia #474): an instance
 * super-admin holds no other role, in any team, directly or through a live
 * group.
 *
 * write() is the one supported path for role and group writes (the product's
 * forms, OIDC role sync, API, its own commands): it runs the write in a
 * transaction on the user's connection with the user row locked, checks the
 * resulting state and rolls everything back when it breaks the rule. Writes
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
     * Runs a role or group write for one user atomically. Call it once per
     * affected user (a group write: once per member, or nest the calls).
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $write
     * @return TResult
     *
     * @throws ExclusiveSuperAdminException after rolling the write back
     */
    public function write(Model&Authenticatable $user, Closure $write): mixed
    {
        return $user->getConnection()->transaction(function () use ($user, $write): mixed {
            // Serialises concurrent writes for the user, on the primary.
            $user->newQueryWithoutScopes()->whereKey($user->getKey())->lockForUpdate()->first();

            $result = $write();
            $this->assertHeldAlone($user);

            return $result;
        });
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
