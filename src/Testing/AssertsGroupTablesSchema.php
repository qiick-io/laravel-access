<?php

declare(strict_types=1);

namespace Qiick\Access\Testing;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Conformance helper AC17: the three fixed group tables match standards
 * review §2.4–2.6 (columns, nullability, indexes, FK cascade). Products that
 * adopt the tables (including externa with access.migrations.load = false)
 * call assertShape() so their schema cannot drift from the package.
 */
final class AssertsGroupTablesSchema
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * Throws RuntimeException when any of user_groups, user_group_user or
     * user_group_role is missing or violates §2.4–2.6.
     */
    public function assertShape(): void
    {
        $this->assertUserGroups();
        $this->assertUserGroupUser();
        $this->assertUserGroupRole();
    }

    private function assertUserGroups(): void
    {
        $this->requireTable('user_groups');
        $this->requireColumns('user_groups', [
            'id',
            'public_id',
            'name',
            'description',
            'metadata',
            'created_at',
            'updated_at',
            'deleted_at',
        ]);
        $this->requireNotNullable('user_groups', 'id');
        $this->requireNotNullable('user_groups', 'public_id');
        $this->requireNotNullable('user_groups', 'name');
        $this->requireNullable('user_groups', 'description');
        $this->requireNullable('user_groups', 'metadata');
        $this->requireNullable('user_groups', 'deleted_at');
        $this->requireUniqueCovering('user_groups', 'public_id');
        $this->requireUniqueCovering('user_groups', 'name');
    }

    private function assertUserGroupUser(): void
    {
        $this->requireTable('user_group_user');
        $this->requireColumns('user_group_user', [
            'user_group_id',
            'user_id',
            'expires_at',
            'created_at',
            'updated_at',
        ]);
        $this->forbidColumn('user_group_user', 'id');
        $this->requireNotNullable('user_group_user', 'user_group_id');
        $this->requireNotNullable('user_group_user', 'user_id');
        $this->requireNullable('user_group_user', 'expires_at');
        $this->requireUniqueCovering('user_group_user', 'user_group_id', 'user_id');
        $this->requireIndexCovering('user_group_user', 'user_id');
        $this->requireIndexCovering('user_group_user', 'expires_at');
        $this->requireForeignKeyCascade('user_group_user', 'user_group_id', 'user_groups');
        $this->requireForeignKeyCascade('user_group_user', 'user_id', 'users');
    }

    private function assertUserGroupRole(): void
    {
        $rolesTable = config('permission.table_names.roles', 'roles');
        $rolesTable = is_string($rolesTable) && $rolesTable !== '' ? $rolesTable : 'roles';

        $this->requireTable('user_group_role');
        $this->requireColumns('user_group_role', [
            'user_group_id',
            'role_id',
            'created_at',
            'updated_at',
        ]);
        $this->forbidColumn('user_group_role', 'id');
        $this->requireNotNullable('user_group_role', 'user_group_id');
        $this->requireNotNullable('user_group_role', 'role_id');
        $this->requireUniqueCovering('user_group_role', 'user_group_id', 'role_id');
        $this->requireIndexCovering('user_group_role', 'role_id');
        $this->requireForeignKeyCascade('user_group_role', 'user_group_id', 'user_groups');
        $this->requireForeignKeyCascade('user_group_role', 'role_id', $rolesTable);
    }

    private function requireTable(string $table): void
    {
        if (! Schema::connection($this->connection->getName())->hasTable($table)) {
            throw new RuntimeException("AC17: expected table [{$table}] to exist (§2.4–2.6).");
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function requireColumns(string $table, array $columns): void
    {
        foreach ($columns as $column) {
            if (! Schema::connection($this->connection->getName())->hasColumn($table, $column)) {
                throw new RuntimeException(
                    "AC17: expected column [{$table}.{$column}] (§2.4–2.6).",
                );
            }
        }
    }

    private function forbidColumn(string $table, string $column): void
    {
        if (Schema::connection($this->connection->getName())->hasColumn($table, $column)) {
            throw new RuntimeException(
                "AC17: column [{$table}.{$column}] must be absent (no surrogate pivot id, §2.5–2.6).",
            );
        }
    }

    private function requireNullable(string $table, string $column): void
    {
        if (! $this->isNullable($table, $column)) {
            throw new RuntimeException(
                "AC17: column [{$table}.{$column}] must be nullable (§2.4–2.6).",
            );
        }
    }

    private function requireNotNullable(string $table, string $column): void
    {
        if ($this->isNullable($table, $column)) {
            throw new RuntimeException(
                "AC17: column [{$table}.{$column}] must not be nullable (§2.4–2.6).",
            );
        }
    }

    private function isNullable(string $table, string $column): bool
    {
        foreach ($this->connection->getSchemaBuilder()->getColumns($table) as $definition) {
            if (($definition['name'] ?? null) === $column) {
                return (bool) ($definition['nullable'] ?? false);
            }
        }

        throw new RuntimeException(
            "AC17: column [{$table}.{$column}] missing while checking nullability.",
        );
    }

    private function requireUniqueCovering(string $table, string ...$columns): void
    {
        $wanted = array_values($columns);

        foreach ($this->connection->getSchemaBuilder()->getIndexes($table) as $index) {
            if (! ($index['unique'] ?? false)) {
                continue;
            }

            if (array_values($index['columns'] ?? []) === $wanted) {
                return;
            }
        }

        throw new RuntimeException(
            'AC17: expected unique index on ['.$table.'.'.implode(',', $wanted).'] (§2.4–2.6).',
        );
    }

    private function requireIndexCovering(string $table, string ...$columns): void
    {
        $wanted = array_values($columns);

        foreach ($this->connection->getSchemaBuilder()->getIndexes($table) as $index) {
            if (array_values($index['columns'] ?? []) === $wanted) {
                return;
            }
        }

        throw new RuntimeException(
            'AC17: expected index on ['.$table.'.'.implode(',', $wanted).'] (§2.4–2.6).',
        );
    }

    private function requireForeignKeyCascade(string $table, string $column, string $foreignTable): void
    {
        foreach ($this->connection->getSchemaBuilder()->getForeignKeys($table) as $foreignKey) {
            $keyColumns = array_values($foreignKey['columns'] ?? []);

            if ($keyColumns !== [$column]) {
                continue;
            }

            $referenced = (string) ($foreignKey['foreign_table'] ?? '');
            $onDelete = strtolower((string) ($foreignKey['on_delete'] ?? ''));

            if ($referenced !== $foreignTable) {
                throw new RuntimeException(
                    "AC17: [{$table}.{$column}] must reference [{$foreignTable}], got [{$referenced}].",
                );
            }

            if ($onDelete !== 'cascade') {
                throw new RuntimeException(
                    "AC17: [{$table}.{$column}] FK onDelete must be cascade, got [{$onDelete}].",
                );
            }

            return;
        }

        throw new RuntimeException(
            "AC17: expected cascade FK [{$table}.{$column}] → [{$foreignTable}] (§2.5–2.6).",
        );
    }
}
