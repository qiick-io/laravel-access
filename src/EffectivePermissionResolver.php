<?php

declare(strict_types=1);

namespace Qiick\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Qiick\Access\Contracts\ActorResolver;
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
     * Keyed by principal type, then identifier, then exact class, then team.
     * The type is part of the key: two Authenticatable models (a web user and
     * another guard's principal) can share an identifier and must never share
     * permissions. The class too: a group source may admit one class only, so
     * morph siblings never share a group grant; forget() drops every class of
     * the principal at once, so a role event on one still clears the other.
     *
     * @var array<string, array<string, array<class-string, array<string, array{permissions: list<string>, assigned_permissions: list<string>, role_names: list<string>, role_ids: list<string>}>>>>
     */
    private array $cache = [];

    /**
     * Super-admin verdicts by principal type, identifier, then exact class.
     * No team key: the verdict is instance-level (C30), so one read serves
     * every team.
     *
     * @var array<string, array<string, array<class-string, bool>>>
     */
    private array $superAdminVerdicts = [];

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
     * Super-admin authority for the current actor, for use in product policies.
     * A verified credential never inherits this authority from its owner.
     * A bound user must match the policy's actor, as it must for Gate::before.
     *
     * @api
     */
    public function isActorSuperAdmin(?Authenticatable $actor): bool
    {
        if ($actor === null) {
            return false;
        }

        $context = app(ActorResolver::class)->current();

        return ! $context->isApiActor()
            && ($context->user === null || $this->isSamePrincipal($context->user, $actor))
            && $this->isSuperAdmin($actor);
    }

    /**
     * Super-admin is instance-level: the role assignment counts in whichever
     * team it was made, so a zero-organisation admin still matches (C30).
     * Only a global role (no team id on the role) counts: a team-local role
     * named super-admin, which an organisation admin may be able to create,
     * never mints an instance super-admin. Direct and instance-group roles.
     * Use isActorSuperAdmin() when deciding the current actor's authority.
     */
    public function isSuperAdmin(Authenticatable $user): bool
    {
        $principalType = $this->principalType($user);
        $identifier = $this->identifierKey($user);

        return $this->superAdminVerdicts[$principalType][$identifier][$user::class] ??= in_array(
            $this->roleCatalog->superAdminRoleName(),
            $this->globalRoleNamesIgnoringTeamFilter($user),
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
            $this->directGlobalRoleNamesIgnoringTeamFilter($user),
            true,
        );
    }

    /**
     * Same Spatie principal: morph class (Spatie's model_type) and identifier.
     * Another guard's principal can share the identifier without being the
     * same user; a subclass sharing the morph class is the same principal.
     */
    public function isSamePrincipal(Authenticatable $first, Authenticatable $second): bool
    {
        return $this->principalType($first) === $this->principalType($second)
            && $first->getAuthIdentifier() === $second->getAuthIdentifier();
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

    /**
     * Whether the permission is actually assigned to the user (directly,
     * through a direct role or through an active group role), ignoring the
     * super-admin grant. Use isWithinOwnerCeiling() for what an API key may
     * reach; pass `fresh` for use-time checks behind a credential (§4.2).
     */
    public function hasAssignedPermission(Authenticatable $user, string $name, bool $fresh = false): bool
    {
        if ($fresh) {
            $this->forget($user);
        }

        return in_array($name, $this->resolve($user)['assigned_permissions'], true);
    }

    /**
     * The owner ceiling behind an API credential: what a key owned by this
     * user may reach at most, before the credential's own scopes narrow it.
     * Assigned permissions, or, for an instance super-admin, every permission
     * of the vocabulary (decision 2026-11-26): a super-admin alone can then
     * issue a working key. Never the Gate bypass itself (design §3.1 step 2):
     * a name outside the vocabulary, such as one only the super-admin role
     * row carries, stays out. Pass `fresh` for use-time checks, so a demoted
     * owner's keys lose the grant on the next request (§4.2).
     */
    public function isWithinOwnerCeiling(Authenticatable $owner, string $name, bool $fresh = false): bool
    {
        if ($this->hasAssignedPermission($owner, $name, $fresh)) {
            return true;
        }

        return $this->isSuperAdmin($owner) && $this->vocabulary->isPermission($name);
    }

    public function forget(?Authenticatable $user = null): void
    {
        if ($user === null) {
            $this->flush();

            return;
        }

        $principalType = $this->principalType($user);
        $identifier = $this->identifierKey($user);

        unset(
            $this->cache[$principalType][$identifier],
            $this->superAdminVerdicts[$principalType][$identifier],
        );

        $this->unsetRelations($user, 'roles', 'permissions', 'groups');
    }

    public function flush(): void
    {
        $this->cache = [];
        $this->superAdminVerdicts = [];
    }

    /** @internal Counts the subject's process-local verdicts for erasure previews. */
    public function cachedVerdictCount(Authenticatable $user): int
    {
        $principalType = $this->principalType($user);
        $identifier = $this->identifierKey($user);

        return array_sum(array_map(count(...), $this->cache[$principalType][$identifier] ?? []))
            + count($this->superAdminVerdicts[$principalType][$identifier] ?? []);
    }

    /**
     * @return array{permissions: list<string>, assigned_permissions: list<string>, role_names: list<string>, role_ids: list<string>}
     */
    private function resolve(Authenticatable $user): array
    {
        $principalType = $this->principalType($user);
        $identifier = $this->identifierKey($user);
        $teamKey = $this->teamKey();

        if (isset($this->cache[$principalType][$identifier][$user::class][$teamKey])) {
            return $this->cache[$principalType][$identifier][$user::class][$teamKey];
        }

        // Loaded Eloquent relations may predate erasure or belong to the team
        // that was current when they were loaded (C29).
        if ($user instanceof Model) {
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }

        // The same verdict as isSuperAdmin(), with the instance-level grant
        // added to roles without bringing in another organisation's roles (C30).
        $isSuperAdmin = $this->isSuperAdmin($user);
        $directRoleIds = $this->pluckRoleIds($user);
        $directRoleNames = $this->pluckRoleNames($user);
        $groupRoleIds = $this->groupRoles->roleIdsFor($user);
        $groupRoleNames = $this->roleNamesForIds($groupRoleIds);

        $roleIds = $this->uniqueSortedIds([...$directRoleIds, ...$groupRoleIds]);

        $assignedPermissions = $this->uniqueSortedStrings([
            ...$this->pluckPermissionNames($user),
            ...$this->permissionNamesForRoleIds($groupRoleIds),
        ]);
        $roleNames = [...$directRoleNames, ...$groupRoleNames];

        if ($isSuperAdmin) {
            $roleNames[] = $this->roleCatalog->superAdminRoleName();
        }

        return $this->cache[$principalType][$identifier][$user::class][$teamKey] = [
            'permissions' => $isSuperAdmin ? $this->vocabulary->all() : $assignedPermissions,
            'assigned_permissions' => $assignedPermissions,
            'role_names' => $this->uniqueSortedStrings($roleNames),
            'role_ids' => $roleIds,
        ];
    }

    /**
     * @return list<string>
     */
    private function globalRoleNamesIgnoringTeamFilter(Authenticatable $user): array
    {
        // Instance groups only — organisation membership must not mint
        // instance super-admin (is_instance / C30).
        return $this->uniqueSortedStrings([
            ...$this->directGlobalRoleNamesIgnoringTeamFilter($user),
            ...$this->roleNamesForIds($this->groupRoles->instanceRoleIdsFor($user), true),
        ]);
    }

    /**
     * Direct global role names across every team (C30). Under teams this is a
     * query on a `roles` relation built without Spatie's team filter and
     * restricted to roles with no team id; nothing is loaded on the user, so
     * neither a filtered relation loaded earlier nor this unfiltered read can
     * leak between teams.
     *
     * @return list<string>
     */
    private function directGlobalRoleNamesIgnoringTeamFilter(Authenticatable $user): array
    {
        if (! $this->registrar->teams) {
            if ($user instanceof Model) {
                $user->unsetRelation('roles');
            }

            return $this->pluckRoleNames($user);
        }

        // Spatie applies the team filter when it builds the relation.
        $this->registrar->teams = false;

        try {
            $relation = method_exists($user, 'roles') ? $user->roles() : null;

            if (! $relation instanceof BelongsToMany) {
                // Not an Eloquent HasRoles model: no team-local roles exist.
                return $this->pluckRoleNames($user);
            }
        } finally {
            $this->registrar->teams = true;
        }

        $roleModel = $relation->getRelated();

        return $this->stringsOnly(
            $relation
                ->whereNull($roleModel->qualifyColumn($this->registrar->teamsKey))
                ->pluck($roleModel->qualifyColumn('name'))
                ->all(),
        );
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

    /**
     * Spatie's identity for a model is its morph class (model_type), so a
     * sibling class sharing it is the same principal: a role event on one
     * clears the other's entry. The resolved connection name keeps users of
     * two databases with the same id apart, in identity and in the cache (the
     * boot checks make each reported name a connection's own key). JSON keeps
     * the pair unambiguous when either part contains a separator.
     */
    private function principalType(Authenticatable $user): string
    {
        if ($user instanceof Model) {
            return json_encode([$user->getConnection()->getName(), $user->getMorphClass()], JSON_THROW_ON_ERROR);
        }

        return $user::class;
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

        // The role model's own key; objects that are not an Eloquent relation
        // (test doubles, custom role stores) expose `id`.
        $roleKeyName = $relation instanceof BelongsToMany ? $relation->getRelated()->getKeyName() : 'id';
        $selectedKey = $relation instanceof BelongsToMany ? $relation->getRelated()->getQualifiedKeyName() : 'id';
        $ids = [];

        foreach (Collection::wrap($relation->get([$selectedKey])) as $role) {
            if (! is_object($role) || ! method_exists($role, 'getAttribute')) {
                continue;
            }

            $roleId = $role->getAttribute($roleKeyName);

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

        return $this->stringsOnly(Collection::wrap($user->getRoleNames())->all());
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private function stringsOnly(array $values): array
    {
        $strings = [];

        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $strings[] = $value;
            }
        }

        return $strings;
    }

    /**
     * Direct permissions and those of direct roles, except the super-admin
     * role's own: a super-admin's web grant is the whole vocabulary anyway,
     * and an API actor's owner ceiling must never include anything that comes
     * from the super-admin role (design §6).
     *
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

        if (! $user instanceof Model) {
            // Not an Eloquent HasRoles model: no role to set apart.
            return $this->permissionNamesOf(Collection::wrap($user->getAllPermissions())->all());
        }

        // Spatie's direct permissions are the `permissions` relation loaded above.
        $permissions = Collection::wrap($user->getRelationValue('permissions'))->all();

        foreach (Collection::wrap($user->getRelationValue('roles'))->all() as $role) {
            if (! $role instanceof Model || $role->getAttribute('name') === $this->roleCatalog->superAdminRoleName()) {
                continue;
            }

            $permissions = [...$permissions, ...Collection::wrap($role->getRelationValue('permissions'))->all()];
        }

        return $this->permissionNamesOf($permissions);
    }

    /**
     * @param  array<array-key, mixed>  $permissions
     * @return list<string>
     */
    private function permissionNamesOf(array $permissions): array
    {
        $names = [];

        foreach ($permissions as $permission) {
            if (is_object($permission) && method_exists($permission, 'getAttribute')) {
                $names[] = $permission->getAttribute('name');
            }
        }

        return $this->stringsOnly($names);
    }

    /**
     * @param  list<int|string>  $roleIds
     * @return list<string>
     */
    private function roleNamesForIds(array $roleIds, bool $isGlobalOnly = false): array
    {
        if ($roleIds === []) {
            return [];
        }

        $roleClass = $this->registrar->getRoleClass();

        if (! is_a($roleClass, Model::class, true)) {
            return [];
        }

        $roleModel = new $roleClass;
        $query = $roleClass::query()->whereIn($roleModel->getKeyName(), $roleIds);

        if ($isGlobalOnly && $this->registrar->teams) {
            $query->whereNull($this->registrar->teamsKey);
        }

        return $this->stringsOnly($query->pluck('name')->all());
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

        $roleClass = $this->registrar->getRoleClass();

        if (! is_a($roleClass, Model::class, true)) {
            return [];
        }

        // The super-admin role's own permissions never count (§6). Compared
        // in PHP, exactly like direct roles: a SQL `!=` would follow the
        // column collation and also drop look-alikes such as `Super-Admin`
        // on case-insensitive MySQL / MariaDB.
        $roleModel = new $roleClass;
        $roleIdsExceptSuperAdmin = $roleClass::query()
            ->whereIn($roleModel->getKeyName(), $roleIds)
            ->get([$roleModel->getKeyName(), 'name'])
            ->reject(fn (Model $role): bool => $role->getAttribute('name') === $this->roleCatalog->superAdminRoleName())
            ->modelKeys();

        $names = [];

        foreach (
            $permissionClass::query()
                ->whereHas('roles', static function ($query) use ($roleModel, $roleIdsExceptSuperAdmin): void {
                    $query->whereIn($roleModel->getQualifiedKeyName(), $roleIdsExceptSuperAdmin);
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
