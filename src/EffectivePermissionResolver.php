<?php

declare(strict_types=1);

namespace Qiick\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\Contracts\PermissionVocabulary;
use Qiick\Access\Contracts\RoleCatalog;
use Qiick\Access\Contracts\TeamContext;
use Spatie\Permission\PermissionRegistrar;

/**
 * Resolves effective roles and permissions from direct Spatie assignments and
 * active group roles (design §4). Process-local cache keyed by user and team;
 * scoped per request/job so a missed clear cannot outlive one unit of work.
 *
 * Super-admin is resolved without the Spatie team filter (C30). Group role
 * permission names are loaded for the returned role ids (already team-specific
 * rows when teams are on).
 *
 * @api
 */
final class EffectivePermissionResolver
{
    /**
     * @var array<string, array{permissions: list<string>, role_names: list<string>, role_ids: list<int|string>}>
     */
    private array $cache = [];

    public function __construct(
        private readonly RoleCatalog $roleCatalog,
        private readonly GroupRoleSource $groupRoles,
        private readonly PermissionVocabulary $vocabulary,
        private readonly TeamContext $teamContext,
        private readonly PermissionRegistrar $registrar,
    ) {}

    /**
     * @return list<string>
     */
    public function roleNamesFor(Authenticatable $user): array
    {
        return $this->resolve($user)['role_names'];
    }

    /**
     * @return list<int|string>
     */
    public function effectiveRoleIds(Authenticatable $user): array
    {
        return $this->resolve($user)['role_ids'];
    }

    /**
     * Super-admin is instance-level: role lookup ignores the current Spatie team
     * filter so a zero-organisation admin still matches (C30).
     */
    public function isSuperAdmin(Authenticatable $user): bool
    {
        return in_array(
            $this->roleCatalog->superAdminRoleName(),
            $this->roleNamesIgnoringTeamFilter($user),
            true,
        );
    }

    /**
     * @return list<string>
     */
    public function permissionsFor(Authenticatable $user): array
    {
        return $this->resolve($user)['permissions'];
    }

    public function hasPermission(Authenticatable $user, string $name, bool $fresh = false): bool
    {
        if ($fresh) {
            $this->forget($user);
        }

        if ($this->isSuperAdmin($user)) {
            return $this->vocabulary->isPermission($name);
        }

        return in_array($name, $this->permissionsFor($user), true);
    }

    public function forget(?Authenticatable $user = null): void
    {
        if ($user === null) {
            $this->flush();

            return;
        }

        $prefix = $this->userKey($user).':';

        foreach (array_keys($this->cache) as $cacheKey) {
            if (str_starts_with($cacheKey, $prefix)) {
                unset($this->cache[$cacheKey]);
            }
        }

        if (method_exists($user, 'unsetRelation')) {
            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');
            $user->unsetRelation('groups');
        }
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * @return array{permissions: list<string>, role_names: list<string>, role_ids: list<int|string>}
     */
    private function resolve(Authenticatable $user): array
    {
        $cacheKey = $this->userKey($user).':'.$this->teamKey();

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $directRoleIds = $this->pluckRoleIds($user);
        $directRoleNames = $this->pluckRoleNames($user);
        $groupRoleIds = $this->groupRoles->roleIdsFor($user);
        $groupRoleNames = $this->roleNamesForIds($groupRoleIds);

        $roleIds = $this->uniqueSortedIds(array_merge($directRoleIds, $groupRoleIds));
        $roleNames = $this->uniqueSortedStrings(array_merge($directRoleNames, $groupRoleNames));

        if (in_array($this->roleCatalog->superAdminRoleName(), $roleNames, true)) {
            $permissions = $this->vocabulary->all();
        } else {
            $permissions = $this->uniqueSortedStrings(array_merge(
                $this->pluckPermissionNames($user),
                $this->permissionNamesForRoleIds($groupRoleIds),
            ));
        }

        return $this->cache[$cacheKey] = [
            'permissions' => $permissions,
            'role_names' => $roleNames,
            'role_ids' => $roleIds,
        ];
    }

    /**
     * @return list<string>
     */
    private function roleNamesIgnoringTeamFilter(Authenticatable $user): array
    {
        $teamsWereEnabled = $this->registrar->teams;

        if ($teamsWereEnabled) {
            $this->registrar->teams = false;
        }

        try {
            return $this->uniqueSortedStrings(array_merge(
                $this->pluckRoleNames($user),
                $this->roleNamesForIds($this->groupRoles->roleIdsFor($user)),
            ));
        } finally {
            if ($teamsWereEnabled) {
                $this->registrar->teams = true;
            }
        }
    }

    private function teamKey(): string
    {
        $teamId = $this->teamContext->currentId();

        if ($teamId === null) {
            return 'null';
        }

        if (is_int($teamId)) {
            return (string) $teamId;
        }

        return $teamId;
    }

    private function userKey(Authenticatable $user): string
    {
        $identifier = $user->getAuthIdentifier();

        if (is_int($identifier)) {
            return (string) $identifier;
        }

        if (is_string($identifier)) {
            return $identifier;
        }

        return 'unknown';
    }

    /**
     * @return list<int|string>
     */
    private function pluckRoleIds(Authenticatable $user): array
    {
        if (! is_callable([$user, 'roles'])) {
            return [];
        }

        $relation = call_user_func([$user, 'roles']);
        if (! is_object($relation) || ! is_callable([$relation, 'get'])) {
            return [];
        }

        $roles = call_user_func([$relation, 'get'], ['id']);
        if (! is_iterable($roles)) {
            return [];
        }

        $ids = [];
        foreach ($roles as $role) {
            if (! is_object($role) || ! is_callable([$role, 'getAttribute'])) {
                continue;
            }

            $roleId = call_user_func([$role, 'getAttribute'], 'id');
            if (is_int($roleId) || is_string($roleId)) {
                $ids[] = $roleId;
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function pluckRoleNames(Authenticatable $user): array
    {
        if (! is_callable([$user, 'getRoleNames'])) {
            return [];
        }

        $roleNames = call_user_func([$user, 'getRoleNames']);
        if (! is_iterable($roleNames)) {
            return [];
        }

        $names = [];
        foreach ($roleNames as $roleName) {
            if (is_string($roleName) && $roleName !== '') {
                $names[] = $roleName;
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function pluckPermissionNames(Authenticatable $user): array
    {
        if (! is_callable([$user, 'getAllPermissions'])) {
            return [];
        }

        if (is_callable([$user, 'loadMissing'])) {
            call_user_func([$user, 'loadMissing'], ['permissions', 'roles.permissions']);
        }

        $permissions = call_user_func([$user, 'getAllPermissions']);
        if (! is_iterable($permissions)) {
            return [];
        }

        $names = [];
        foreach ($permissions as $permission) {
            if (! is_object($permission) || ! is_callable([$permission, 'getAttribute'])) {
                continue;
            }

            $permissionName = call_user_func([$permission, 'getAttribute'], 'name');
            if (is_string($permissionName) && $permissionName !== '') {
                $names[] = $permissionName;
            }
        }

        return $names;
    }

    /**
     * @param  list<int|string>  $roleIds
     * @return list<string>
     */
    private function roleNamesForIds(array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }

        $roleClass = $this->registrar->getRoleClass();
        if (! is_a($roleClass, Model::class, true)) {
            return [];
        }

        $names = [];
        foreach ($roleClass::query()->whereIn('id', $roleIds)->pluck('name') as $roleName) {
            if (is_string($roleName) && $roleName !== '') {
                $names[] = $roleName;
            }
        }

        return $names;
    }

    /**
     * @param  list<int|string>  $roleIds
     * @return list<string>
     */
    private function permissionNamesForRoleIds(array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }

        $permissionClass = $this->registrar->getPermissionClass();
        if (! is_a($permissionClass, Model::class, true)) {
            return [];
        }

        $names = [];
        foreach (
            $permissionClass::query()
                ->whereHas('roles', static function ($query) use ($roleIds): void {
                    $query->whereIn('roles.id', $roleIds);
                })
                ->pluck('name') as $permissionName
        ) {
            if (is_string($permissionName) && $permissionName !== '') {
                $names[] = $permissionName;
            }
        }

        return $names;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function uniqueSortedStrings(array $values): array
    {
        $unique = array_values(array_unique($values));
        sort($unique);

        return $unique;
    }

    /**
     * @param  list<int|string>  $values
     * @return list<int|string>
     */
    private function uniqueSortedIds(array $values): array
    {
        $unique = [];
        foreach ($values as $value) {
            $unique[is_int($value) ? (string) $value : $value] = $value;
        }

        $list = array_values($unique);
        usort(
            $list,
            static fn (int|string $left, int|string $right): int => (is_int($left) ? (string) $left : $left)
                <=> (is_int($right) ? (string) $right : $right),
        );

        return $list;
    }
}
