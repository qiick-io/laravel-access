<?php

declare(strict_types=1);

namespace Qiick\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\Contracts\PermissionVocabulary;
use Qiick\Access\Contracts\RoleCatalog;
use Qiick\Access\Contracts\TeamContext;
use Spatie\Permission\PermissionRegistrar;

/**
 * Resolves effective roles and permissions from direct Spatie assignments and
 * active group roles (design §4). Process-local cache keyed by user type,
 * identifier and team; scoped per request/job so a missed clear cannot outlive
 * one unit of work.
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
     * Keyed by user type, then identifier, then team. The type is part of the
     * key: two Authenticatable models (a web user and another guard's
     * principal) can share an identifier and must never share permissions.
     *
     * @var array<class-string, array<string, array<string, array{permissions: list<string>, role_names: list<string>, role_ids: list<string>}>>>
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
     * @return list<string>
     */
    public function effectiveRoleIds(Authenticatable $user): array
    {
        return $this->resolve($user)['role_ids'];
    }

    /**
     * Super-admin is instance-level: role lookup ignores the current Spatie team
     * filter so a zero-organisation admin still matches (C30). Direct and group.
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
     * Direct Spatie assignment of the super-admin role only (no group path),
     * with the team filter off (C30 / P6). Break-glass policies in products use
     * this with TwoFactorPolicy::hasUsableFactor(); group-only super-admins are
     * not break-glass eligible (Q3).
     */
    public function isDirectSuperAdmin(Authenticatable $user): bool
    {
        return in_array(
            $this->roleCatalog->superAdminRoleName(),
            $this->directRoleNamesIgnoringTeamFilter($user),
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

        unset($this->cache[$user::class][$this->identifierKey($user)]);

        $this->unsetRelations($user, 'roles', 'permissions', 'groups');
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * @return array{permissions: list<string>, role_names: list<string>, role_ids: list<string>}
     */
    private function resolve(Authenticatable $user): array
    {
        $userType = $user::class;
        $identifier = $this->identifierKey($user);
        $teamKey = $this->teamKey();

        if (isset($this->cache[$userType][$identifier][$teamKey])) {
            return $this->cache[$userType][$identifier][$teamKey];
        }

        // Under teams Spatie filters these relations by the team current when
        // they were loaded, possibly another one than this entry's (C29).
        if ($this->registrar->teams) {
            $this->unsetRelations($user, 'roles', 'permissions');
        }

        $directRoleIds = $this->pluckRoleIds($user);
        $directRoleNames = $this->pluckRoleNames($user);
        $groupRoleIds = $this->groupRoles->roleIdsFor($user);
        $groupRoleNames = $this->roleNamesForIds($groupRoleIds);

        $roleIds = $this->uniqueSortedIds([...$directRoleIds, ...$groupRoleIds]);

        // C30 / B2: decide super-admin with the same team-agnostic names as
        // isSuperAdmin(), so permissionsFor / roleNamesFor agree under teams.
        $roleNamesIgnoringTeam = $this->roleNamesIgnoringTeamFilter($user);

        if (in_array($this->roleCatalog->superAdminRoleName(), $roleNamesIgnoringTeam, true)) {
            return $this->cache[$userType][$identifier][$teamKey] = [
                'permissions' => $this->vocabulary->all(),
                'role_names' => $roleNamesIgnoringTeam,
                'role_ids' => $roleIds,
            ];
        }

        return $this->cache[$userType][$identifier][$teamKey] = [
            'permissions' => $this->uniqueSortedStrings([
                ...$this->pluckPermissionNames($user),
                ...$this->permissionNamesForRoleIds($groupRoleIds),
            ]),
            'role_names' => $this->uniqueSortedStrings([...$directRoleNames, ...$groupRoleNames]),
            'role_ids' => $roleIds,
        ];
    }

    /**
     * @return list<string>
     */
    private function roleNamesIgnoringTeamFilter(Authenticatable $user): array
    {
        // Instance groups only — organisation membership must not mint
        // instance super-admin (is_instance / C30).
        return $this->uniqueSortedStrings([
            ...$this->directRoleNamesIgnoringTeamFilter($user),
            ...$this->roleNamesForIds($this->groupRoles->instanceRoleIdsFor($user)),
        ]);
    }

    /**
     * Direct role names across every team (C30). Under teams the `roles`
     * relation is unset before the read, so a relation loaded with the current
     * team's filter cannot hide an instance super-admin, and again after it,
     * so the unfiltered relation never outlives this call: Spatie's
     * getRoleNames() and getAllPermissions() reuse a loaded relation, and an
     * unfiltered one would grant another organisation's roles in the current
     * team, even on a `fresh` check.
     *
     * @return list<string>
     */
    private function directRoleNamesIgnoringTeamFilter(Authenticatable $user): array
    {
        $teamsWereEnabled = $this->registrar->teams;

        if (! $teamsWereEnabled) {
            return $this->pluckRoleNames($user);
        }

        $this->unsetRelations($user, 'roles');
        $this->registrar->teams = false;

        try {
            return $this->pluckRoleNames($user);
        } finally {
            $this->registrar->teams = true;
            $this->unsetRelations($user, 'roles');
        }
    }

    private function unsetRelations(Authenticatable $user, string ...$relations): void
    {
        if (! method_exists($user, 'unsetRelation')) {
            return;
        }

        foreach ($relations as $relation) {
            $user->unsetRelation($relation);
        }
    }

    private function teamKey(): string
    {
        return var_export($this->teamContext->currentId(), true);
    }

    private function identifierKey(Authenticatable $user): string
    {
        $identifier = $user->getAuthIdentifier();

        if (is_int($identifier) || is_string($identifier)) {
            return (string) $identifier;
        }

        return 'unknown';
    }

    /**
     * @return list<int|string>
     */
    private function pluckRoleIds(Authenticatable $user): array
    {
        if (! method_exists($user, 'roles')) {
            return [];
        }

        $relation = $user->roles();

        if (! is_object($relation) || ! method_exists($relation, 'get')) {
            return [];
        }

        $ids = [];

        foreach (Collection::wrap($relation->get(['id'])) as $role) {
            if (! is_object($role) || ! method_exists($role, 'getAttribute')) {
                continue;
            }

            $roleId = $role->getAttribute('id');

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
        if (! method_exists($user, 'getRoleNames')) {
            return [];
        }

        $names = [];

        foreach (Collection::wrap($user->getRoleNames()) as $roleName) {
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
        if (! method_exists($user, 'getAllPermissions')) {
            return [];
        }

        if (method_exists($user, 'loadMissing')) {
            $user->loadMissing(['permissions', 'roles.permissions']);
        }

        $names = [];

        foreach (Collection::wrap($user->getAllPermissions()) as $permission) {
            if (! is_object($permission) || ! method_exists($permission, 'getAttribute')) {
                continue;
            }

            $permissionName = $permission->getAttribute('name');

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
            if (is_string($roleName)) {
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
            if (is_string($permissionName)) {
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
        $unique = array_unique($values);
        sort($unique);

        return $unique;
    }

    /**
     * @param  list<int|string>  $values
     * @return list<string>
     */
    private function uniqueSortedIds(array $values): array
    {
        $unique = array_unique(array_map(
            static fn (int|string $value): string => (string) $value,
            $values,
        ));
        sort($unique, SORT_STRING);

        return $unique;
    }
}
