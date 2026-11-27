<?php

declare(strict_types=1);

namespace Qiick\Access;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Arr;
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
        private readonly ConfigRepository $config,
    ) {}

    /**
     * Ids of the users who hold super-admin together with another role, so
     * their bypass is withheld; `access:check` lists them. Candidates are the
     * holders of a role named super-admin, directly or through a live
     * instance group, each then read fresh on the primary.
     *
     * @return array<int, string> the keys, JSON-encoded
     */
    public function mixedHolderIds(): array
    {
        $userClass = $this->config->get('auth.providers.users.model');

        if (! is_string($userClass) || ! class_exists($userClass)) {
            return [];
        }

        $user = new $userClass;
        $roles = method_exists($user, 'roles') ? $user->roles() : null;

        if (! $roles instanceof MorphToMany) {
            return [];
        }

        // Inside a transaction every read goes to the primary.
        $user = $roles->getParent();

        return $user->getConnection()->transaction(function () use ($user, $roles): array {
            $roleModel = $roles->getRelated();
            $superAdminName = $this->roleCatalog->superAdminRoleName();
            $connection = $user->getConnection();

            $candidates = $connection->table($roles->getTable())
                ->join($roleModel->getTable(), $roleModel->getQualifiedKeyName(), '=', $roles->getQualifiedRelatedPivotKeyName())
                ->where($roleModel->qualifyColumn('name'), $superAdminName)
                ->where($roles->qualifyPivotColumn($roles->getMorphType()), $user->getMorphClass())
                ->pluck($roles->getQualifiedForeignPivotKeyName())
                ->all();

            if ($connection->getSchemaBuilder()->hasTable('user_group_role')) {
                $candidates = [...$candidates, ...$connection->table('user_group_user')
                    ->join('user_groups', 'user_groups.id', '=', 'user_group_user.user_group_id')
                    ->join('user_group_role', 'user_group_role.user_group_id', '=', 'user_groups.id')
                    ->join($roleModel->getTable(), $roleModel->getQualifiedKeyName(), '=', 'user_group_role.role_id')
                    ->where($roleModel->qualifyColumn('name'), $superAdminName)
                    ->where('user_groups.is_instance', true)
                    ->whereNull('user_groups.deleted_at')
                    ->pluck('user_group_user.user_id')
                    ->all()];
            }

            return $user->newQueryWithoutScopes()->whereKey(collect($candidates)->unique()->values()->all())->get()
                ->filter(fn (Model $holder): bool => $holder instanceof Authenticatable && $this->resolver->holdsMixedSuperAdmin($holder))
                // JSON keeps an int and a string key apart in the listing.
                ->map(fn (Model $holder): string => json_encode($holder->getKey(), JSON_THROW_ON_ERROR))
                ->values()
                ->all();
        });
    }

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
     * @param  (Model&Authenticatable)|list<Model&Authenticatable>  $users
     * @param  Closure(): TResult  $write
     * @return TResult
     *
     * @throws ExclusiveSuperAdminException after rolling the write back
     * @throws LogicException when the users live on different connections or a user row is missing
     */
    public function write(Model|array $users, Closure $write, ?Authenticatable $actor = null): mixed
    {
        $users = Arr::wrap($users);
        // A stable lock order, so two writes over the same users cannot deadlock.
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

                    if ($actor !== null && ! $wasEffective[$index] && $this->resolver->isSuperAdmin($user) && ! $this->guard()->canManageSuperAdmins($actor)) {
                        throw ExclusiveSuperAdminException::promotionBy($user);
                    }
                }

                return $result;
            });
        } finally {
            // Whatever the outcome, no verdict read inside the transaction survives it.
            foreach ($users as $user) {
                // forget() also drops the loaded roles relation.
                $this->resolver->forget($user);
            }
        }
    }

    /**
     * @param  list<Model&Authenticatable>  $users
     */
    private function connectionOf(array $users): Connection
    {
        $connections = array_unique(array_map(fn (Model $user): int => spl_object_id($user->getConnection()), $users));

        if (count($connections) !== 1) {
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
        // A fresh read: holdsMixedSuperAdmin() forgets the user and its loaded roles.
        if ($this->resolver->holdsMixedSuperAdmin($user)) {
            throw ExclusiveSuperAdminException::forUser($user);
        }
    }
}
