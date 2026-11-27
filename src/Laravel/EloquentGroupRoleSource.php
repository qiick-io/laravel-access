<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\Contracts\ListsAllGroupRoles;
use Qiick\Access\Contracts\TeamContext;

/**
 * Group roles from the fixed package tables (user_groups / pivots). Soft-deleted
 * groups contribute nothing (C7). Memberships whose expires_at is in the past
 * contribute nothing (standards MUST A2 / AC15). Table names are fixed by the
 * package (Q5) — never configurable.
 *
 * Applies instance groups always, organisation groups when
 * {@see TeamContext::currentId()} matches `organisation_id`, and legacy
 * unscoped rows (`is_instance = false`, `organisation_id` null) for products
 * that do not use organisations (externa 1.x). Super-admin elevation through
 * groups uses only {@see instanceRoleIdsFor()} (is_instance groups).
 *
 * Memberships belong to one model: user_group_user.user_id points at its
 * table. Only instances of exactly that class (`access.group_member_model`,
 * default `auth.providers.users.model`) on the default connection get group
 * roles; another guard's principal sharing an identifier, a subclass, a user
 * of another connection or a non-Eloquent user gets none.
 *
 * Resolves the connection per call so a singleton binding stays Octane-safe.
 *
 * @api
 */
final class EloquentGroupRoleSource implements GroupRoleSource, ListsAllGroupRoles
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly TeamContext $teamContext,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @return list<int|string>
     */
    public function roleIdsFor(Authenticatable $user): array
    {
        $teamId = $this->teamContext->currentId();

        return $this->pluckRoleIds($user, function (Builder $query) use ($teamId): void {
            $query->where(function (Builder $scope) use ($teamId): void {
                $scope->where('user_groups.is_instance', true)
                    ->orWhere(function (Builder $legacy): void {
                        $legacy->where('user_groups.is_instance', false)
                            ->whereNull('user_groups.organisation_id');
                    });

                if ($teamId !== null) {
                    $scope->orWhere('user_groups.organisation_id', $teamId);
                }
            });
        });
    }

    /**
     * @return list<int|string>
     */
    public function instanceRoleIdsFor(Authenticatable $user): array
    {
        return $this->pluckRoleIds($user, function (Builder $query): void {
            $query->where('user_groups.is_instance', true);
        });
    }

    /**
     * Every live group of the user, in every organisation.
     *
     * @return list<int|string>
     */
    public function allRoleIdsFor(Authenticatable $user): array
    {
        return $this->pluckRoleIds($user, static function (Builder $query): void {});
    }

    /**
     * @param  Closure(Builder): void  $constrainGroups
     * @return list<int|string>
     */
    private function pluckRoleIds(Authenticatable $user, Closure $constrainGroups): array
    {
        $groupConnectionName = $this->database->getDefaultConnection();

        if (! $this->isGroupMember($user, $groupConnectionName)) {
            return [];
        }

        $now = now();

        $query = $this->database->connection($groupConnectionName)
            ->table('user_group_user')
            ->join(
                'user_groups',
                'user_groups.id',
                '=',
                'user_group_user.user_group_id',
            )
            ->join(
                'user_group_role',
                'user_group_role.user_group_id',
                '=',
                'user_groups.id',
            )
            ->where('user_group_user.user_id', $user->getAuthIdentifier())
            ->whereNull('user_groups.deleted_at')
            ->where(function (Builder $expires) use ($now): void {
                $expires->whereNull('user_group_user.expires_at')
                    ->orWhere('user_group_user.expires_at', '>', $now);
            });

        $constrainGroups($query);

        $roleIds = $query
            ->distinct()
            ->orderBy('user_group_role.role_id')
            ->pluck('user_group_role.role_id')
            ->all();

        $normalized = [];
        foreach ($roleIds as $roleId) {
            if (is_int($roleId) || is_string($roleId)) {
                $normalized[] = $roleId;
            }
        }

        return $normalized;
    }

    /**
     * user_group_user.user_id holds ids of the member model's table on the
     * group tables' connection (the default one). Membership counts only for
     * exactly that class (an STI product configures the subclass) on exactly
     * that connection name, compared as declared, never by resolving where
     * it points: the boot checks make a loaded model's name its connection's
     * key, and docs/adoption.md makes that connection one dataset. A
     * non-Eloquent user carries no connection, so it gets nothing.
     *
     * @param  string  $groupConnectionName  the connection the group tables are read from
     */
    private function isGroupMember(Authenticatable $user, string $groupConnectionName): bool
    {
        $memberModel = $this->config->get('access.group_member_model')
            ?? $this->config->get('auth.providers.users.model');

        if (! $user instanceof Model || $user::class !== $memberModel) {
            return false;
        }

        $member = new $memberModel;

        return $user->getTable() === $member->getTable()
            && ($user->getConnectionName() ?? $groupConnectionName) === $groupConnectionName
            && ($member->getConnectionName() ?? $groupConnectionName) === $groupConnectionName;
    }
}
