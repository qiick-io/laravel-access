<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Qiick\Access\Contracts\GroupRoleSource;

/**
 * Group roles from the package tables (user_groups / pivots by default).
 * Soft-deleted groups contribute nothing (C7). Memberships whose expires_at
 * is in the past contribute nothing (standards MUST A2 / AC15). Table names
 * come from access.table_names (config only — never env).
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
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @return list<int|string>
     */
    public function roleIdsFor(Authenticatable $user): array
    {
        $now = now();
        $groupsTable = $this->tableName('groups', 'user_groups');
        $groupUserTable = $this->tableName('group_user', 'user_group_user');
        $groupRoleTable = $this->tableName('group_role', 'user_group_role');

        $roleIds = $this->database->connection()
            ->table($groupUserTable)
            ->join(
                $groupsTable,
                "{$groupsTable}.id",
                '=',
                "{$groupUserTable}.user_group_id",
            )
            ->join(
                $groupRoleTable,
                "{$groupRoleTable}.user_group_id",
                '=',
                "{$groupsTable}.id",
            )
            ->where("{$groupUserTable}.user_id", $user->getAuthIdentifier())
            ->whereNull("{$groupsTable}.deleted_at")
            ->where(function (Builder $query) use ($now, $groupUserTable): void {
                $query->whereNull("{$groupUserTable}.expires_at")
                    ->orWhere("{$groupUserTable}.expires_at", '>', $now);
            })
            ->distinct()
            ->orderBy("{$groupRoleTable}.role_id")
            ->pluck("{$groupRoleTable}.role_id")
            ->all();

        $normalized = [];
        foreach ($roleIds as $roleId) {
            if (is_int($roleId) || is_string($roleId)) {
                $normalized[] = $roleId;
            }
        }

        return $normalized;
    }

    private function tableName(string $key, string $default): string
    {
        $value = $this->config->get("access.table_names.{$key}", $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
