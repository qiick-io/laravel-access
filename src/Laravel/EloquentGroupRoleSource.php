<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Qiick\Access\Contracts\GroupRoleSource;

/**
 * Group roles from the package tables (user_groups / pivots). Soft-deleted
 * groups contribute nothing (C7). Memberships whose expires_at is in the past
 * contribute nothing (standards MUST A2 / AC15). Table names are fixed by the
 * package migration — never env-configurable.
 *
 * Role ids returned here are instance-level; EffectivePermissionResolver must
 * load those roles' permissions in the current Spatie team (design §4.1).
 *
 * Resolves the connection per call so a singleton binding stays Octane-safe.
 *
 * @api
 */
final class EloquentGroupRoleSource implements GroupRoleSource
{
    public function __construct(
        private readonly DatabaseManager $database,
    ) {}

    /**
     * @return list<int|string>
     */
    public function roleIdsFor(Authenticatable $user): array
    {
        $now = now();

        $roleIds = $this->database->connection()
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
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('user_group_user.expires_at')
                    ->orWhere('user_group_user.expires_at', '>', $now);
            })
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
