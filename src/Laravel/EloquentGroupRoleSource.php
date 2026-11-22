<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\ConnectionInterface;
use Qiick\Access\Contracts\GroupRoleSource;

/**
 * Group roles from the fleet tables (user_groups / pivots). Soft-deleted
 * groups contribute nothing via whereNull(deleted_at) (design C7).
 *
 * @api
 */
final class EloquentGroupRoleSource implements GroupRoleSource
{
    /**
     * @param  array{
     *     table?: mixed,
     *     user_pivot?: mixed,
     *     role_pivot?: mixed,
     *     user_foreign_key?: mixed,
     *     group_foreign_key?: mixed,
     *     role_foreign_key?: mixed
     * }  $groupsConfig
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly array $groupsConfig,
    ) {}

    /**
     * @return list<int|string>
     */
    public function roleIdsFor(Authenticatable $user): array
    {
        $groupsTable = $this->stringConfig('table', 'user_groups');
        $userPivot = $this->stringConfig('user_pivot', 'user_group_user');
        $rolePivot = $this->stringConfig('role_pivot', 'user_group_role');
        $userForeignKey = $this->stringConfig('user_foreign_key', 'user_id');
        $groupForeignKey = $this->stringConfig('group_foreign_key', 'user_group_id');
        $roleForeignKey = $this->stringConfig('role_foreign_key', 'role_id');

        $roleIds = $this->connection
            ->table($userPivot)
            ->join(
                $groupsTable,
                "{$groupsTable}.id",
                '=',
                "{$userPivot}.{$groupForeignKey}",
            )
            ->join(
                $rolePivot,
                "{$rolePivot}.{$groupForeignKey}",
                '=',
                "{$groupsTable}.id",
            )
            ->where("{$userPivot}.{$userForeignKey}", $user->getAuthIdentifier())
            ->whereNull("{$groupsTable}.deleted_at")
            ->distinct()
            ->orderBy("{$rolePivot}.{$roleForeignKey}")
            ->pluck("{$rolePivot}.{$roleForeignKey}")
            ->all();

        $normalized = [];
        foreach ($roleIds as $roleId) {
            if (is_int($roleId) || is_string($roleId)) {
                $normalized[] = $roleId;
            }
        }

        return $normalized;
    }

    private function stringConfig(string $key, string $default): string
    {
        $value = $this->groupsConfig[$key] ?? $default;

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
