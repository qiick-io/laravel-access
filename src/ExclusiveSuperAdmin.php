<?php

declare(strict_types=1);

namespace Qiick\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Qiick\Access\Contracts\RoleCatalog;

/**
 * Super-admin stands alone (decision 2026-11-26): an instance super-admin
 * holds no other role, directly or through a group, on every write path.
 *
 * Spatie role writes (`assignRole`, `syncRoles`, `permission:assign-role`,
 * OIDC role sync, API code) are enforced for products: AccessServiceProvider
 * reverts a breaking attach and throws. Group writes are the product's own
 * tables, so a product calls assertHeldAlone() for every affected member after
 * changing a group's roles or members, inside its transaction.
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

        return in_array($this->superAdminName(), $names, true) && count($names) > 1;
    }

    /**
     * Whether the user is an instance super-admin who also holds another role
     * through their direct roles or their groups, read fresh.
     *
     * shortcut: reads the current team context only; a role in another
     * organisation is not seen. Upgrade if a product mixes instance
     * super-admin with organisation roles.
     *
     * @phpstan-impure
     */
    public function isBrokenFor(Authenticatable $user): bool
    {
        $this->resolver->forget($user);

        return $this->resolver->isSuperAdmin($user) && $this->breaks($this->resolver->roleNamesFor($user));
    }

    /**
     * @throws ExclusiveSuperAdminException when the user holds super-admin with another role
     */
    public function assertHeldAlone(Authenticatable $user): void
    {
        if ($this->isBrokenFor($user)) {
            throw ExclusiveSuperAdminException::forUser($user);
        }
    }

    /**
     * Runs after a Spatie role attach. A breaking attach is undone so no write
     * path leaves super-admin mixed: the other roles the call named come off
     * first, so a call that names super-admin never ends above what it asked
     * for; if the user still breaks the rule (roles held before or through a
     * group), the super-admin role it named comes off too. Then it throws, so
     * the caller's transaction, if any, rolls back the whole write.
     *
     * @internal
     *
     * @throws ExclusiveSuperAdminException
     */
    public function revertBreakingAttach(Model&Authenticatable $user, mixed $rolesOrIds): void
    {
        if (! $this->isBrokenFor($user)) {
            return;
        }

        $relation = method_exists($user, 'roles') ? $user->roles() : null;

        if ($relation instanceof BelongsToMany) {
            $ids = Collection::wrap($rolesOrIds)
                ->map(fn (mixed $role): mixed => $role instanceof Model ? $role->getKey() : $role)
                ->filter(fn (mixed $id): bool => is_int($id) || is_string($id))
                ->all();
            $superAdminIds = $relation->getRelated()->newQuery()->whereKey($ids)->get()
                ->filter(fn (Model $role): bool => is_string($name = $role->getAttribute('name')) && mb_strtolower($name) === $this->superAdminName())
                ->modelKeys();

            $relation->detach(array_diff($ids, $superAdminIds));

            if ($this->isBrokenFor($user)) {
                $relation->detach($superAdminIds);
                $this->resolver->forget($user);
            }
        }

        $user->unsetRelation('roles');

        throw ExclusiveSuperAdminException::forUser($user);
    }

    private function superAdminName(): string
    {
        return mb_strtolower($this->roleCatalog->superAdminRoleName());
    }
}
