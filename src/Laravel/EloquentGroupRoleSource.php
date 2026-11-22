<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Qiick\Access\Contracts\GroupRoleSource;
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
 * Resolves the connection per call so a singleton binding stays Octane-safe.
 *
 * @api
 */
final class EloquentGroupRoleSource implements GroupRoleSource
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly TeamContext $teamContext,
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
     * @param  Closure(Builder): void  $constrainGroups
     * @return list<int|string>
     */
    private function pluckRoleIds(Authenticatable $user, Closure $constrainGroups): array
    {
        $now = now();

        $query = $this->database->connection()
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
}
